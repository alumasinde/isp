// Command isp-engine is the Go network backend for the ISP billing system.
//
// It owns every router-facing operation: the expiry engine (hotspot voucher
// validity and billing-driven PPP suspension) plus the internal control API the
// PHP panel uses for instant suspend/restore/disconnect actions.
package main

import (
	"context"
	"errors"
	"fmt"
	"log/slog"
	"net/http"
	"os"
	"os/signal"
	"syscall"
	"time"
	_ "time/tzdata" // embed the timezone database for Windows binaries

	"github.com/codepagol/isp/backend/internal/config"
	"github.com/codepagol/isp/backend/internal/engine"
	"github.com/codepagol/isp/backend/internal/httpapi"
	"github.com/codepagol/isp/backend/internal/routeros"
	"github.com/codepagol/isp/backend/internal/store"
)

func main() {
	if err := run(); err != nil {
		fmt.Fprintln(os.Stderr, "isp-engine:", err)
		os.Exit(1)
	}
}

func run() error {
	cfg, err := config.Load()
	if err != nil {
		return err
	}

	log := newLogger(cfg.LogLevel)
	slog.SetDefault(log)

	db, err := store.Open(cfg.DBDSN, cfg.AppKey)
	if err != nil {
		return err
	}
	defer db.Close()

	pingCtx, cancelPing := context.WithTimeout(context.Background(), 10*time.Second)
	defer cancelPing()
	if err := db.Ping(pingCtx); err != nil {
		return fmt.Errorf("database unreachable: %w", err)
	}

	ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	defer stop()

	pool := routeros.NewPool(routeros.DefaultDialer, 2*time.Minute)
	defer pool.Close()

	eng := engine.New(db, pool, engine.Options{
		Timezone:       cfg.Timezone,
		ExpiredProfile: cfg.ExpiredProfile,
		Concurrency:    cfg.Concurrency,
		RouterTimeout:  cfg.RouterTimeout,
		PPPAutoDisable: cfg.PPPAutoDisable,
	}, log)

	server := &http.Server{
		Addr:              cfg.HTTPAddr,
		Handler:           httpapi.New(eng, cfg.Token, log).Handler(),
		ReadHeaderTimeout: 5 * time.Second,
		ReadTimeout:       30 * time.Second,
		WriteTimeout:      3 * time.Minute, // sweeps can take a while
		IdleTimeout:       60 * time.Second,
	}

	log.Info("network engine starting",
		"addr", cfg.HTTPAddr,
		"timezone", cfg.Timezone.String(),
		"sweep_interval", cfg.SweepInterval.String(),
		"ppp_autodisable", cfg.PPPAutoDisable)

	serveErr := make(chan error, 1)
	go func() {
		if err := server.ListenAndServe(); err != nil && !errors.Is(err, http.ErrServerClosed) {
			serveErr <- err
			return
		}
		serveErr <- nil
	}()

	go func() {
		if err := eng.Run(ctx, cfg.SweepInterval); err != nil && !errors.Is(err, context.Canceled) {
			log.Error("expiry loop stopped", "error", err)
		}
	}()

	if cfg.RunOnStart {
		go eng.SweepAndLog(ctx)
	}

	select {
	case err := <-serveErr:
		if err != nil {
			return fmt.Errorf("http server: %w", err)
		}
	case <-ctx.Done():
		log.Info("shutdown signal received")
	}

	shutdownCtx, cancelShutdown := context.WithTimeout(context.Background(), 15*time.Second)
	defer cancelShutdown()
	if err := server.Shutdown(shutdownCtx); err != nil {
		return fmt.Errorf("shutdown: %w", err)
	}

	log.Info("network engine stopped")
	return nil
}

func newLogger(level string) *slog.Logger {
	var lvl slog.Level
	switch level {
	case "debug":
		lvl = slog.LevelDebug
	case "warn":
		lvl = slog.LevelWarn
	case "error":
		lvl = slog.LevelError
	default:
		lvl = slog.LevelInfo
	}

	return slog.New(slog.NewJSONHandler(os.Stdout, &slog.HandlerOptions{Level: lvl}))
}
