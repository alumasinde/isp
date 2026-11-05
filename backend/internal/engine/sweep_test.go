package engine

import (
	"context"
	"io"
	"log/slog"
	"sync"
	"testing"
	"time"

	"github.com/codepagol/isp/backend/internal/routeros"
	"github.com/codepagol/isp/backend/internal/routeros/routerostest"
	"github.com/codepagol/isp/backend/internal/store"
)

type fakeStore struct {
	mu         sync.Mutex
	routers    []store.Router
	vouchers   []store.Voucher
	candidates []store.PPPCandidate
	settings   store.Settings
	deleted    []int64
	marked     []string
}

func (f *fakeStore) Ping(context.Context) error { return nil }

func (f *fakeStore) ConnectedRouters(context.Context) ([]store.Router, error) {
	return f.routers, nil
}

func (f *fakeStore) RouterByName(_ context.Context, name string) (*store.Router, error) {
	for i := range f.routers {
		if f.routers[i].Name == name {
			r := f.routers[i]
			return &r, nil
		}
	}
	return nil, nil
}

func (f *fakeStore) RealtimeVouchers(context.Context) ([]store.Voucher, error) {
	return f.vouchers, nil
}

func (f *fakeStore) DeleteVoucher(_ context.Context, id int64) error {
	f.mu.Lock()
	defer f.mu.Unlock()
	f.deleted = append(f.deleted, id)
	return nil
}

func (f *fakeStore) DeleteVoucherByUser(context.Context, string, string) error { return nil }

func (f *fakeStore) PPPCandidates(context.Context) ([]store.PPPCandidate, error) {
	return f.candidates, nil
}

func (f *fakeStore) MarkCustomerDisabled(_ context.Context, c store.PPPCandidate, mode, _ string) (bool, error) {
	f.mu.Lock()
	defer f.mu.Unlock()
	f.marked = append(f.marked, c.CustomerUniqueID+":"+mode)
	return true, nil
}

func (f *fakeStore) Settings(context.Context) (store.Settings, error) { return f.settings, nil }

func (f *fakeStore) deletedIDs() []int64 {
	f.mu.Lock()
	defer f.mu.Unlock()
	return append([]int64(nil), f.deleted...)
}

func (f *fakeStore) markedEntries() []string {
	f.mu.Lock()
	defer f.mu.Unlock()
	return append([]string(nil), f.marked...)
}

func quietLogger() *slog.Logger {
	return slog.New(slog.NewTextHandler(io.Discard, nil))
}

func TestSweepExpiresOnlyDueVouchers(t *testing.T) {
	srv, err := routerostest.Start(func(call routerostest.Call) ([]map[string]string, string) {
		switch call.Command {
		case "/ip/hotspot/user/print":
			return []map[string]string{{".id": "*1", "name": call.Queries["name"]}}, ""
		default:
			return nil, ""
		}
	})
	if err != nil {
		t.Fatalf("start fake router: %v", err)
	}
	defer srv.Close()

	now := time.Date(2026, time.November, 5, 12, 0, 0, 0, time.UTC)

	fake := &fakeStore{routers: []store.Router{{
		ID: 1, Name: "core", Host: "127.0.0.1", APIPort: srv.Port(),
		Username: "admin", Password: "secret",
	}},
		vouchers: []store.Voucher{
			{ID: 10, RouterName: "core", Username: "voucher-1", ValidityDuration: "1h", FirstLoginAt: now.Add(-2 * time.Hour)},
			{ID: 11, RouterName: "core", Username: "voucher-2", ValidityDuration: "1h", FirstLoginAt: now.Add(-10 * time.Minute)},
		},
		settings: store.Settings{DisableCheckNo: 1, ExpiredProfile: "Expired"},
	}

	pool := routeros.NewPool(nil, time.Minute)
	defer pool.Close()

	eng := New(fake, pool, Options{Timezone: time.UTC, RouterTimeout: 2 * time.Second}, quietLogger())
	eng.now = func() time.Time { return now }

	summary, err := eng.Sweep(context.Background())
	if err != nil {
		t.Fatalf("Sweep: %v", err)
	}

	if summary.HotspotExpired != 1 {
		t.Fatalf("expired %d vouchers, want 1 (%+v)", summary.HotspotExpired, summary.Failures)
	}
	if got := fake.deletedIDs(); len(got) != 1 || got[0] != 10 {
		t.Fatalf("deleted vouchers %v, want [10]", got)
	}

	var removed int
	for _, call := range srv.Calls() {
		if call.Command == "/ip/hotspot/user/remove" {
			removed++
			if call.Attrs[".id"] != "*1" {
				t.Fatalf("removed unexpected id %q", call.Attrs[".id"])
			}
		}
		if call.Command == "/ip/hotspot/user/print" && call.Queries["name"] == "voucher-2" {
			t.Fatal("printed the still-valid voucher")
		}
	}
	if removed != 1 {
		t.Fatalf("sent %d remove commands, want 1", removed)
	}
}

func TestSweepSuspendsDuePPPCustomer(t *testing.T) {
	srv, err := routerostest.Start(func(call routerostest.Call) ([]map[string]string, string) {
		if call.Command == "/ppp/secret/print" {
			return []map[string]string{{".id": "*7", "name": call.Queries["name"]}}, ""
		}
		return nil, ""
	})
	if err != nil {
		t.Fatalf("start fake router: %v", err)
	}
	defer srv.Close()

	now := time.Date(2026, time.November, 5, 12, 0, 0, 0, time.UTC)
	past := now.AddDate(0, 0, -3)

	fake := &fakeStore{
		routers: []store.Router{{
			ID: 1, Name: "core", Host: "127.0.0.1", APIPort: srv.Port(),
			Username: "admin", Password: "secret",
		}},
		candidates: []store.PPPCandidate{{
			CustomerUniqueID: "C-1",
			PPPUserID:        7,
			Username:         "ppp1",
			RouterName:       "core",
			MonthlyRent:      1000,
			DueAmount:        2000,
			AutoDisableMonth: ptr(1),
			AutoDisableDate:  &past,
		}},
		settings: store.Settings{DisableCheckNo: 1, ExpiredProfile: "Expired"},
	}

	pool := routeros.NewPool(nil, time.Minute)
	defer pool.Close()

	eng := New(fake, pool, Options{
		Timezone:       time.UTC,
		RouterTimeout:  2 * time.Second,
		PPPAutoDisable: true,
	}, quietLogger())
	eng.now = func() time.Time { return now }

	summary, err := eng.Sweep(context.Background())
	if err != nil {
		t.Fatalf("Sweep: %v", err)
	}

	if summary.PPPDisabled != 1 {
		t.Fatalf("disabled %d customers, want 1 (%+v)", summary.PPPDisabled, summary.Failures)
	}
	if got := fake.markedEntries(); len(got) != 1 || got[0] != "C-1:soft" {
		t.Fatalf("marked %v, want [C-1:soft]", got)
	}

	var profileSet bool
	for _, call := range srv.Calls() {
		if call.Command == "/ppp/secret/set" && call.Attrs["profile"] == "Expired" {
			profileSet = true
		}
	}
	if !profileSet {
		t.Fatal("engine never moved the secret to the expired profile")
	}
}

func TestSweepSkipsPPPWhenDisabled(t *testing.T) {
	fake := &fakeStore{
		routers: []store.Router{{ID: 1, Name: "core", Host: "127.0.0.1", APIPort: 8728}},
		candidates: []store.PPPCandidate{{
			CustomerUniqueID: "C-1", Username: "ppp1", RouterName: "core",
			MonthlyRent: 1000, DueAmount: 5000, AutoDisableMonth: ptr(1),
			AutoDisableDate: ptr(time.Date(2026, time.January, 1, 0, 0, 0, 0, time.UTC)),
		}},
		settings: store.Settings{DisableCheckNo: 1},
	}

	pool := routeros.NewPool(nil, time.Minute)
	defer pool.Close()

	eng := New(fake, pool, Options{Timezone: time.UTC, PPPAutoDisable: false}, quietLogger())

	summary, err := eng.Sweep(context.Background())
	if err != nil {
		t.Fatalf("Sweep: %v", err)
	}
	if summary.PPPDisabled != 0 || len(fake.markedEntries()) != 0 {
		t.Fatalf("PPP sweep ran while disabled: %+v", summary)
	}
}

func TestSweepSerializesConcurrentRuns(t *testing.T) {
	fake := &fakeStore{settings: store.Settings{DisableCheckNo: 1}}
	pool := routeros.NewPool(nil, time.Minute)
	defer pool.Close()

	eng := New(fake, pool, Options{Timezone: time.UTC}, quietLogger())
	eng.sweepMu.Lock() // simulate an in-flight sweep
	defer eng.sweepMu.Unlock()

	if _, err := eng.Sweep(context.Background()); err != ErrSweepBusy {
		t.Fatalf("got %v, want ErrSweepBusy", err)
	}
}
