package routeros_test

import (
	"context"
	"errors"
	"testing"
	"time"

	"github.com/codepagol/isp/backend/internal/routeros"
	"github.com/codepagol/isp/backend/internal/routeros/routerostest"
)

func TestClientRunReadsItemsAndSendsQuery(t *testing.T) {
	srv, err := routerostest.Start(func(call routerostest.Call) ([]map[string]string, string) {
		if call.Command != "/ip/hotspot/user/print" {
			t.Errorf("unexpected command %q", call.Command)
		}
		return []map[string]string{
			{".id": "*1", "name": "voucher-1"},
			{".id": "*2", "name": "voucher-2"},
		}, ""
	})
	if err != nil {
		t.Fatalf("start fake router: %v", err)
	}
	defer srv.Close()

	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()

	client, err := routeros.Dial(ctx, srv.Addr(), "admin", "secret", 2*time.Second)
	if err != nil {
		t.Fatalf("Dial: %v", err)
	}
	defer client.Close()

	items, err := client.Run("/ip/hotspot/user/print", nil, routeros.Match("name", "voucher-1"))
	if err != nil {
		t.Fatalf("Run: %v", err)
	}
	if len(items) != 2 {
		t.Fatalf("got %d items, want 2", len(items))
	}
	if items[0].Get(".id") != "*1" || items[0].Get("name") != "voucher-1" {
		t.Fatalf("unexpected first item: %#v", items[0])
	}

	calls := srv.Calls()
	if len(calls) != 1 {
		t.Fatalf("got %d calls, want 1", len(calls))
	}
	if calls[0].Queries["name"] != "voucher-1" {
		t.Fatalf("query not sent: %#v", calls[0].Queries)
	}
}

func TestClientRunSurfacesTrap(t *testing.T) {
	srv, err := routerostest.Start(func(call routerostest.Call) ([]map[string]string, string) {
		return nil, "no such item"
	})
	if err != nil {
		t.Fatalf("start fake router: %v", err)
	}
	defer srv.Close()

	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()

	client, err := routeros.Dial(ctx, srv.Addr(), "admin", "secret", 2*time.Second)
	if err != nil {
		t.Fatalf("Dial: %v", err)
	}
	defer client.Close()

	_, err = client.Run("/ppp/secret/set", map[string]string{".id": "*1", "profile": "Expired"})
	if err == nil {
		t.Fatal("expected trap error, got nil")
	}

	var trap *routeros.TrapError
	if !errors.As(err, &trap) {
		t.Fatalf("expected *TrapError, got %T: %v", err, err)
	}
	if trap.Message != "no such item" {
		t.Fatalf("got message %q", trap.Message)
	}
}

func TestPoolReusesAndReconnects(t *testing.T) {
	srv, err := routerostest.Start(func(call routerostest.Call) ([]map[string]string, string) {
		return nil, ""
	})
	if err != nil {
		t.Fatalf("start fake router: %v", err)
	}
	defer srv.Close()

	pool := routeros.NewPool(nil, time.Minute)
	defer pool.Close()

	target := routeros.Target{
		Name: "core", Host: "127.0.0.1", Port: srv.Port(),
		Username: "admin", Password: "secret", Timeout: 2 * time.Second,
	}
	ctx := context.Background()

	for i := 0; i < 3; i++ {
		if err := pool.Do(ctx, target, func(cl *routeros.Client) error {
			_, err := cl.Run("/system/identity/print", nil)
			return err
		}); err != nil {
			t.Fatalf("Do #%d: %v", i, err)
		}
	}

	if conns := srv.Connections(); conns != 1 {
		t.Fatalf("pool opened %d connections, want 1", conns)
	}

	pool.Drop("core")
	if err := pool.Do(ctx, target, func(cl *routeros.Client) error {
		_, err := cl.Run("/system/identity/print", nil)
		return err
	}); err != nil {
		t.Fatalf("Do after Drop: %v", err)
	}

	if conns := srv.Connections(); conns != 2 {
		t.Fatalf("pool opened %d connections after Drop, want 2", conns)
	}
}
