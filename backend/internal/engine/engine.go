package engine

import (
	"context"
	"errors"
	"fmt"
	"log/slog"
	"sync"
	"time"

	"github.com/codepagol/isp/backend/internal/mikrotik"
	"github.com/codepagol/isp/backend/internal/routeros"
	"github.com/codepagol/isp/backend/internal/store"
)

// ErrSweepBusy is returned when a sweep is already running.
var ErrSweepBusy = errors.New("expiry sweep already running")

// dataStore is the slice of store.Store the engine needs (fakeable in tests).
type dataStore interface {
	Ping(ctx context.Context) error
	ConnectedRouters(ctx context.Context) ([]store.Router, error)
	RouterByName(ctx context.Context, name string) (*store.Router, error)
	RealtimeVouchers(ctx context.Context) ([]store.Voucher, error)
	DeleteVoucher(ctx context.Context, id int64) error
	DeleteVoucherByUser(ctx context.Context, routerName, username string) error
	PPPCandidates(ctx context.Context) ([]store.PPPCandidate, error)
	MarkCustomerDisabled(ctx context.Context, c store.PPPCandidate, mode, reason string) (bool, error)
	Settings(ctx context.Context) (store.Settings, error)
}

// Options tunes one engine instance.
type Options struct {
	Timezone       *time.Location
	ExpiredProfile string
	Concurrency    int
	RouterTimeout  time.Duration
	PPPAutoDisable bool
}

// Failure records one action the engine could not complete.
type Failure struct {
	Router   string `json:"router"`
	Username string `json:"username"`
	Stage    string `json:"stage"`
	Error    string `json:"error"`
}

// Summary reports the outcome of a sweep.
type Summary struct {
	StartedAt      time.Time `json:"started_at"`
	FinishedAt     time.Time `json:"finished_at"`
	Routers        int       `json:"routers"`
	HotspotExpired int       `json:"hotspot_expired"`
	PPPDisabled    int       `json:"ppp_disabled"`
	Failures       []Failure `json:"failures,omitempty"`
}

// RouterInfo is a credential-free router description for the status endpoint.
type RouterInfo struct {
	Name    string `json:"name"`
	Host    string `json:"host"`
	APIPort int    `json:"api_port"`
	SSHPort int    `json:"ssh_port,omitempty"`
	Managed bool   `json:"managed"`
}

// Engine owns all router-side network state.
type Engine struct {
	store dataStore
	pool  *routeros.Pool
	log   *slog.Logger
	opts  Options
	now   func() time.Time

	sweepMu sync.Mutex
}

// New builds an engine. A nil logger falls back to the default slog logger.
func New(ds dataStore, pool *routeros.Pool, opts Options, log *slog.Logger) *Engine {
	if log == nil {
		log = slog.Default()
	}
	if opts.Timezone == nil {
		opts.Timezone = time.UTC
	}
	if opts.Concurrency < 1 {
		opts.Concurrency = 8
	}
	if opts.RouterTimeout <= 0 {
		opts.RouterTimeout = 5 * time.Second
	}
	return &Engine{
		store: ds,
		pool:  pool,
		log:   log,
		opts:  opts,
		now:   time.Now,
	}
}

// Ready reports whether the database is reachable.
func (e *Engine) Ready(ctx context.Context) error { return e.store.Ping(ctx) }

// Run sweeps on a fixed interval until ctx is cancelled.
func (e *Engine) Run(ctx context.Context, interval time.Duration) error {
	if interval <= 0 {
		interval = time.Minute
	}

	ticker := time.NewTicker(interval)
	defer ticker.Stop()

	for {
		select {
		case <-ctx.Done():
			return ctx.Err()
		case <-ticker.C:
			e.SweepAndLog(ctx)
		}
	}
}

// SweepAndLog runs one sweep and logs the result.
func (e *Engine) SweepAndLog(ctx context.Context) Summary {
	summary, err := e.Sweep(ctx)
	if err != nil {
		if errors.Is(err, ErrSweepBusy) {
			e.log.Debug("expiry sweep skipped: previous sweep still running")
			return summary
		}
		e.log.Error("expiry sweep failed", "error", err)
		return summary
	}

	e.log.Info("expiry sweep complete",
		"routers", summary.Routers,
		"hotspot_expired", summary.HotspotExpired,
		"ppp_disabled", summary.PPPDisabled,
		"failures", len(summary.Failures),
		"duration", summary.FinishedAt.Sub(summary.StartedAt).String())

	for _, failure := range summary.Failures {
		e.log.Warn("expiry action failed",
			"router", failure.Router,
			"username", failure.Username,
			"stage", failure.Stage,
			"error", failure.Error)
	}

	return summary
}

type partial struct {
	expired  int
	disabled int
	failures []Failure
}

// Sweep performs one expiry pass over every connected router.
func (e *Engine) Sweep(ctx context.Context) (Summary, error) {
	if !e.sweepMu.TryLock() {
		return Summary{}, ErrSweepBusy
	}
	defer e.sweepMu.Unlock()

	summary := Summary{StartedAt: e.now()}

	routers, err := e.store.ConnectedRouters(ctx)
	if err != nil {
		return summary, fmt.Errorf("load connected routers: %w", err)
	}
	summary.Routers = len(routers)

	settings, err := e.store.Settings(ctx)
	if err != nil {
		e.log.Warn("could not read auto-disable settings, using defaults", "error", err)
		settings = store.Settings{DisableCheckNo: 1, ExpiredProfile: e.opts.ExpiredProfile}
	}
	if e.opts.ExpiredProfile != "" {
		settings.ExpiredProfile = e.opts.ExpiredProfile
	}

	targets := make(map[string]routeros.Target, len(routers))
	for _, r := range routers {
		if r.APIPort <= 0 {
			e.log.Debug("router has no API port, skipping", "router", r.Name)
			continue
		}
		targets[r.Name] = e.targetFor(r)
	}

	sem := make(chan struct{}, e.opts.Concurrency)
	var (
		wg    sync.WaitGroup
		mu    sync.Mutex
		acc   partial
		merge = func(p partial) {
			mu.Lock()
			acc.expired += p.expired
			acc.disabled += p.disabled
			acc.failures = append(acc.failures, p.failures...)
			mu.Unlock()
		}
		launch = func(fn func() partial) {
			wg.Add(1)
			go func() {
				defer wg.Done()
				sem <- struct{}{}
				defer func() { <-sem }()
				merge(fn())
			}()
		}
	)

	if vouchers, err := e.store.RealtimeVouchers(ctx); err != nil {
		acc.failures = append(acc.failures, Failure{Stage: "load_vouchers", Error: err.Error()})
	} else if len(vouchers) > 0 {
		for routerName, list := range groupVouchers(vouchers) {
			target, ok := targets[routerName]
			if !ok {
				for _, v := range list {
					acc.failures = append(acc.failures, Failure{
						Router: routerName, Username: v.Username, Stage: "resolve_router",
						Error: "router is not connected or has no API port",
					})
				}
				continue
			}
			launch(func() partial { return e.sweepHotspot(ctx, target, list) })
		}
	}

	if e.opts.PPPAutoDisable {
		if candidates, err := e.store.PPPCandidates(ctx); err != nil {
			acc.failures = append(acc.failures, Failure{Stage: "load_ppp_candidates", Error: err.Error()})
		} else if len(candidates) > 0 {
			for routerName, list := range groupCandidates(candidates) {
				target, ok := targets[routerName]
				if !ok {
					for _, c := range list {
						if decision := EvaluatePPP(c, settings, e.now(), e.opts.Timezone); decision.Disable {
							acc.failures = append(acc.failures, Failure{
								Router: routerName, Username: c.Username, Stage: "resolve_router",
								Error: "router is not connected or has no API port",
							})
						}
					}
					continue
				}
				launch(func() partial { return e.sweepPPP(ctx, target, list, settings) })
			}
		}
	}

	wg.Wait()

	summary.HotspotExpired = acc.expired
	summary.PPPDisabled = acc.disabled
	if len(acc.failures) > 0 {
		summary.Failures = acc.failures
	}
	summary.FinishedAt = e.now()

	return summary, nil
}

// sweepHotspot expires the vouchers of one router.
func (e *Engine) sweepHotspot(ctx context.Context, target routeros.Target, vouchers []store.Voucher) partial {
	var p partial
	now := e.now()

	for _, v := range vouchers {
		expires, err := VoucherExpiry(v.FirstLoginAt, v.ValidityDuration, e.opts.Timezone)
		if err != nil {
			p.failures = append(p.failures, Failure{
				Router: target.Name, Username: v.Username, Stage: "parse_validity", Error: err.Error(),
			})
			continue
		}
		if now.Before(expires) {
			continue
		}

		if err := e.pool.Do(ctx, target, func(cl *routeros.Client) error {
			return expireHotspotOnRouter(cl, v.Username)
		}); err != nil {
			p.failures = append(p.failures, Failure{
				Router: target.Name, Username: v.Username, Stage: "expire_hotspot", Error: err.Error(),
			})
			continue
		}

		if err := e.store.DeleteVoucher(ctx, v.ID); err != nil {
			p.failures = append(p.failures, Failure{
				Router: target.Name, Username: v.Username, Stage: "delete_voucher", Error: err.Error(),
			})
			continue
		}

		p.expired++
		e.log.Info("expired realtime hotspot voucher",
			"router", target.Name, "username", v.Username, "expired_at", expires.Format(time.RFC3339))
	}

	return p
}

// sweepPPP suspends the customers of one router whose billing says they are due.
func (e *Engine) sweepPPP(ctx context.Context, target routeros.Target, candidates []store.PPPCandidate, settings store.Settings) partial {
	var p partial
	now := e.now()

	for _, c := range candidates {
		decision := EvaluatePPP(c, settings, now, e.opts.Timezone)
		if !decision.Disable {
			continue
		}

		var mode string
		err := e.pool.Do(ctx, target, func(cl *routeros.Client) error {
			m, err := suspendPPPOnRouter(cl, c.Username, settings.ExpiredProfile)
			mode = m
			return err
		})
		if err != nil {
			if errors.Is(err, ErrNotFound) {
				e.log.Warn("ppp secret not found on router, skipping suspension",
					"router", target.Name, "username", c.Username)
				continue
			}
			p.failures = append(p.failures, Failure{
				Router: target.Name, Username: c.Username, Stage: "suspend_ppp", Error: err.Error(),
			})
			continue
		}

		updated, err := e.store.MarkCustomerDisabled(ctx, c, mode, decision.Reason)
		if err != nil {
			p.failures = append(p.failures, Failure{
				Router: target.Name, Username: c.Username, Stage: "mark_customer_disabled", Error: err.Error(),
			})
			continue
		}
		if !updated {
			e.log.Debug("customer state changed during sweep, not counted",
				"customer", c.CustomerUniqueID)
			continue
		}

		p.disabled++
		e.log.Info("suspended PPP customer",
			"router", target.Name,
			"username", c.Username,
			"customer", c.CustomerUniqueID,
			"mode", mode,
			"reason", decision.Reason)
	}

	return p
}

// SuspendPPP suspends one customer on demand (called by the PHP admin panel).
func (e *Engine) SuspendPPP(ctx context.Context, routerName, username string) (string, error) {
	target, err := e.targetForRouter(ctx, routerName)
	if err != nil {
		return "", err
	}

	var mode string
	err = e.pool.Do(ctx, target, func(cl *routeros.Client) error {
		m, err := suspendPPPOnRouter(cl, username, e.expiredProfile(ctx))
		mode = m
		return err
	})
	if err != nil {
		return "", err
	}
	return mode, nil
}

// RestorePPP re-enables a suspended customer.
func (e *Engine) RestorePPP(ctx context.Context, routerName, username string) error {
	target, err := e.targetForRouter(ctx, routerName)
	if err != nil {
		return err
	}
	return e.pool.Do(ctx, target, func(cl *routeros.Client) error {
		return restorePPPOnRouter(cl, username, "")
	})
}

// DisconnectPPP kicks one active PPPoE session.
func (e *Engine) DisconnectPPP(ctx context.Context, routerName, username string) error {
	target, err := e.targetForRouter(ctx, routerName)
	if err != nil {
		return err
	}
	return e.pool.Do(ctx, target, func(cl *routeros.Client) error {
		return disconnectPPPOnRouter(cl, username)
	})
}

// DisconnectHotspot kicks one active hotspot session.
func (e *Engine) DisconnectHotspot(ctx context.Context, routerName, username string) error {
	target, err := e.targetForRouter(ctx, routerName)
	if err != nil {
		return err
	}
	return e.pool.Do(ctx, target, func(cl *routeros.Client) error {
		return disconnectHotspotOnRouter(cl, username)
	})
}

// ExpireHotspot expires one voucher immediately.
func (e *Engine) ExpireHotspot(ctx context.Context, routerName, username string) error {
	target, err := e.targetForRouter(ctx, routerName)
	if err != nil {
		return err
	}
	if err := e.pool.Do(ctx, target, func(cl *routeros.Client) error {
		return expireHotspotOnRouter(cl, username)
	}); err != nil {
		return err
	}
	return e.store.DeleteVoucherByUser(ctx, routerName, username)
}

// Routers lists connected routers without exposing credentials.
func (e *Engine) Routers(ctx context.Context) ([]RouterInfo, error) {
	routers, err := e.store.ConnectedRouters(ctx)
	if err != nil {
		return nil, err
	}

	infos := make([]RouterInfo, 0, len(routers))
	for _, r := range routers {
		infos = append(infos, RouterInfo{
			Name:    r.Name,
			Host:    r.Host,
			APIPort: r.APIPort,
			SSHPort: r.SSHPort,
			Managed: r.APIPort > 0,
		})
	}
	return infos, nil
}

// expiredProfile resolves the walled-garden profile: explicit configuration
// first, then the admin setting stored in main_site_data.
func (e *Engine) expiredProfile(ctx context.Context) string {
	if e.opts.ExpiredProfile != "" {
		return e.opts.ExpiredProfile
	}
	if settings, err := e.store.Settings(ctx); err == nil && settings.ExpiredProfile != "" {
		return settings.ExpiredProfile
	}
	return "Expired"
}

func (e *Engine) targetForRouter(ctx context.Context, routerName string) (routeros.Target, error) {
	router, err := e.store.RouterByName(ctx, routerName)
	if err != nil {
		return routeros.Target{}, err
	}
	if router == nil {
		return routeros.Target{}, fmt.Errorf("router %q is not connected", routerName)
	}
	if router.APIPort <= 0 {
		return routeros.Target{}, fmt.Errorf("router %q has no API port configured", routerName)
	}
	return e.targetFor(*router), nil
}

func (e *Engine) targetFor(r store.Router) routeros.Target {
	return routeros.Target{
		Name:     r.Name,
		Host:     r.Host,
		Port:     r.APIPort,
		Username: r.Username,
		Password: r.Password,
		Timeout:  e.opts.RouterTimeout,
	}
}

func groupVouchers(vouchers []store.Voucher) map[string][]store.Voucher {
	grouped := make(map[string][]store.Voucher)
	for _, v := range vouchers {
		grouped[v.RouterName] = append(grouped[v.RouterName], v)
	}
	return grouped
}

func groupCandidates(candidates []store.PPPCandidate) map[string][]store.PPPCandidate {
	grouped := make(map[string][]store.PPPCandidate)
	for _, c := range candidates {
		grouped[c.RouterName] = append(grouped[c.RouterName], c)
	}
	return grouped
}

// parseDuration is kept here so expiry tests can stay in this package.
func parseDuration(value string) (time.Duration, error) { return mikrotik.ParseDuration(value) }
