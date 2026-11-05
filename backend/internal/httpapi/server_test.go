package httpapi

import (
	"context"
	"io"
	"log/slog"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"

	"github.com/codepagol/isp/backend/internal/engine"
)

type fakeService struct {
	readyErr    error
	suspendMode string
	suspendErr  error
	sweepErr    error
	calls       int
}

func (f *fakeService) Ready(context.Context) error { return f.readyErr }

func (f *fakeService) SuspendPPP(context.Context, string, string) (string, error) {
	f.calls++
	return f.suspendMode, f.suspendErr
}

func (f *fakeService) RestorePPP(context.Context, string, string) error { return nil }
func (f *fakeService) DisconnectPPP(context.Context, string, string) error {
	return nil
}
func (f *fakeService) DisconnectHotspot(context.Context, string, string) error { return nil }
func (f *fakeService) ExpireHotspot(context.Context, string, string) error     { return nil }

func (f *fakeService) Sweep(context.Context) (engine.Summary, error) {
	return engine.Summary{HotspotExpired: 2}, f.sweepErr
}

func (f *fakeService) Routers(context.Context) ([]engine.RouterInfo, error) {
	return []engine.RouterInfo{{Name: "core", APIPort: 8728, Managed: true}}, nil
}

func newHandler(svc Service) http.Handler {
	return New(svc, "test-token", slog.New(slog.NewTextHandler(io.Discard, nil))).Handler()
}

func do(t *testing.T, h http.Handler, method, path, token, body string) *httptest.ResponseRecorder {
	t.Helper()

	req := httptest.NewRequest(method, path, strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	if token != "" {
		req.Header.Set("Authorization", "Bearer "+token)
	}

	rec := httptest.NewRecorder()
	h.ServeHTTP(rec, req)
	return rec
}

func TestControlAPIReturns401WithoutToken(t *testing.T) {
	svc := &fakeService{suspendMode: "soft"}
	h := newHandler(svc)

	rec := do(t, h, http.MethodPost, "/api/v1/ppp/suspend", "", `{"router":"core","username":"ppp1"}`)
	if rec.Code != http.StatusUnauthorized {
		t.Fatalf("got %d, want 401", rec.Code)
	}
	if svc.calls != 0 {
		t.Fatal("unauthorized request reached the service")
	}
}

func TestSuspendPPPReturnsMode(t *testing.T) {
	h := newHandler(&fakeService{suspendMode: "soft"})

	rec := do(t, h, http.MethodPost, "/api/v1/ppp/suspend", "test-token",
		`{"router":"core","username":"ppp1"}`)
	if rec.Code != http.StatusOK {
		t.Fatalf("got %d, want 200: %s", rec.Code, rec.Body.String())
	}
	if !strings.Contains(rec.Body.String(), `"mode":"soft"`) {
		t.Fatalf("unexpected body: %s", rec.Body.String())
	}
}

func TestSuspendPPPValidatesInput(t *testing.T) {
	h := newHandler(&fakeService{})

	rec := do(t, h, http.MethodPost, "/api/v1/ppp/suspend", "test-token", `{"router":"core"}`)
	if rec.Code != http.StatusBadRequest {
		t.Fatalf("got %d, want 400", rec.Code)
	}

	rec = do(t, h, http.MethodPost, "/api/v1/ppp/suspend", "test-token", `not json`)
	if rec.Code != http.StatusBadRequest {
		t.Fatalf("got %d, want 400", rec.Code)
	}
}

func TestControlAPIMapsEngineErrors(t *testing.T) {
	h := newHandler(&fakeService{suspendErr: engine.ErrNotFound})
	if rec := do(t, h, http.MethodPost, "/api/v1/ppp/suspend", "test-token",
		`{"router":"core","username":"ppp1"}`); rec.Code != http.StatusNotFound {
		t.Fatalf("got %d, want 404", rec.Code)
	}

	h = newHandler(&fakeService{sweepErr: engine.ErrSweepBusy})
	if rec := do(t, h, http.MethodPost, "/api/v1/sweep", "test-token", `{}`); rec.Code != http.StatusConflict {
		t.Fatalf("got %d, want 409", rec.Code)
	}
}

func TestSweepReturnsSummary(t *testing.T) {
	h := newHandler(&fakeService{})

	rec := do(t, h, http.MethodPost, "/api/v1/sweep", "test-token", `{}`)
	if rec.Code != http.StatusOK {
		t.Fatalf("got %d, want 200", rec.Code)
	}
	if !strings.Contains(rec.Body.String(), `"hotspot_expired":2`) {
		t.Fatalf("unexpected body: %s", rec.Body.String())
	}
}

func TestRoutersNeverLeakCredentials(t *testing.T) {
	h := newHandler(&fakeService{})

	rec := do(t, h, http.MethodGet, "/api/v1/routers", "test-token", "")
	if rec.Code != http.StatusOK {
		t.Fatalf("got %d, want 200", rec.Code)
	}
	if strings.Contains(rec.Body.String(), "password") || strings.Contains(rec.Body.String(), "username") {
		t.Fatalf("router listing leaked credentials: %s", rec.Body.String())
	}
}

func TestReadyzReportsDatabaseFailure(t *testing.T) {
	h := newHandler(&fakeService{readyErr: context.DeadlineExceeded})

	rec := do(t, h, http.MethodGet, "/readyz", "", "")
	if rec.Code != http.StatusServiceUnavailable {
		t.Fatalf("got %d, want 503", rec.Code)
	}
}

func TestHealthzIsOpen(t *testing.T) {
	rec := do(t, newHandler(&fakeService{}), http.MethodGet, "/healthz", "", "")
	if rec.Code != http.StatusOK {
		t.Fatalf("got %d, want 200", rec.Code)
	}
}
