// Package httpapi exposes the engine's internal control API. The PHP panel
// calls it for immediate actions (suspend/restore/disconnect) while the engine
// keeps owning all router I/O.
package httpapi

import (
	"context"
	"crypto/subtle"
	"encoding/json"
	"errors"
	"log/slog"
	"net/http"
	"strings"
	"time"

	"github.com/codepagol/isp/backend/internal/engine"
)

// Service is the engine surface the API needs.
type Service interface {
	Ready(ctx context.Context) error
	SuspendPPP(ctx context.Context, router, username string) (string, error)
	RestorePPP(ctx context.Context, router, username string) error
	DisconnectPPP(ctx context.Context, router, username string) error
	DisconnectHotspot(ctx context.Context, router, username string) error
	ExpireHotspot(ctx context.Context, router, username string) error
	Sweep(ctx context.Context) (engine.Summary, error)
	Routers(ctx context.Context) ([]engine.RouterInfo, error)
}

// Server binds the service to HTTP handlers.
type Server struct {
	svc   Service
	token []byte
	log   *slog.Logger
}

// New builds the API server.
func New(svc Service, token string, log *slog.Logger) *Server {
	if log == nil {
		log = slog.Default()
	}
	return &Server{svc: svc, token: []byte(token), log: log}
}

// Handler returns the fully wired HTTP handler.
func (s *Server) Handler() http.Handler {
	mux := http.NewServeMux()

	mux.HandleFunc("GET /healthz", s.health)
	mux.HandleFunc("GET /readyz", s.ready)

	mux.Handle("POST /api/v1/ppp/suspend", s.authorize(http.HandlerFunc(s.suspendPPP)))
	mux.Handle("POST /api/v1/ppp/restore", s.authorize(http.HandlerFunc(s.restorePPP)))
	mux.Handle("POST /api/v1/ppp/disconnect", s.authorize(http.HandlerFunc(s.disconnectPPP)))
	mux.Handle("POST /api/v1/hotspot/disconnect", s.authorize(http.HandlerFunc(s.disconnectHotspot)))
	mux.Handle("POST /api/v1/hotspot/expire", s.authorize(http.HandlerFunc(s.expireHotspot)))
	mux.Handle("POST /api/v1/sweep", s.authorize(http.HandlerFunc(s.sweep)))
	mux.Handle("GET /api/v1/routers", s.authorize(http.HandlerFunc(s.routers)))

	return s.withLogging(mux)
}

func (s *Server) health(w http.ResponseWriter, r *http.Request) {
	writeJSON(w, http.StatusOK, map[string]any{"ok": true, "status": "ok"})
}

func (s *Server) ready(w http.ResponseWriter, r *http.Request) {
	ctx, cancel := context.WithTimeout(r.Context(), 3*time.Second)
	defer cancel()

	if err := s.svc.Ready(ctx); err != nil {
		writeJSON(w, http.StatusServiceUnavailable, map[string]any{"ok": false, "error": "database unavailable"})
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"ok": true, "status": "ready"})
}

func (s *Server) suspendPPP(w http.ResponseWriter, r *http.Request) {
	req, ok := decodeTarget(w, r)
	if !ok {
		return
	}

	mode, err := s.svc.SuspendPPP(r.Context(), req.Router, req.Username)
	if err != nil {
		s.routerError(w, err)
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"ok": true, "mode": mode})
}

func (s *Server) restorePPP(w http.ResponseWriter, r *http.Request) {
	req, ok := decodeTarget(w, r)
	if !ok {
		return
	}
	if err := s.svc.RestorePPP(r.Context(), req.Router, req.Username); err != nil {
		s.routerError(w, err)
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"ok": true})
}

func (s *Server) disconnectPPP(w http.ResponseWriter, r *http.Request) {
	req, ok := decodeTarget(w, r)
	if !ok {
		return
	}
	if err := s.svc.DisconnectPPP(r.Context(), req.Router, req.Username); err != nil {
		s.routerError(w, err)
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"ok": true})
}

func (s *Server) disconnectHotspot(w http.ResponseWriter, r *http.Request) {
	req, ok := decodeTarget(w, r)
	if !ok {
		return
	}
	if err := s.svc.DisconnectHotspot(r.Context(), req.Router, req.Username); err != nil {
		s.routerError(w, err)
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"ok": true})
}

func (s *Server) expireHotspot(w http.ResponseWriter, r *http.Request) {
	req, ok := decodeTarget(w, r)
	if !ok {
		return
	}
	if err := s.svc.ExpireHotspot(r.Context(), req.Router, req.Username); err != nil {
		s.routerError(w, err)
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"ok": true})
}

func (s *Server) sweep(w http.ResponseWriter, r *http.Request) {
	ctx, cancel := context.WithTimeout(r.Context(), 2*time.Minute)
	defer cancel()

	summary, err := s.svc.Sweep(ctx)
	if err != nil {
		s.routerError(w, err)
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"ok": true, "summary": summary})
}

func (s *Server) routers(w http.ResponseWriter, r *http.Request) {
	routers, err := s.svc.Routers(r.Context())
	if err != nil {
		s.routerError(w, err)
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"ok": true, "routers": routers})
}

type targetRequest struct {
	Router   string `json:"router"`
	Username string `json:"username"`
}

func decodeTarget(w http.ResponseWriter, r *http.Request) (targetRequest, bool) {
	r.Body = http.MaxBytesReader(w, r.Body, 1<<20)

	var req targetRequest
	decoder := json.NewDecoder(r.Body)
	if err := decoder.Decode(&req); err != nil {
		writeJSON(w, http.StatusBadRequest, map[string]any{"ok": false, "error": "invalid JSON body"})
		return targetRequest{}, false
	}

	req.Router = strings.TrimSpace(req.Router)
	req.Username = strings.TrimSpace(req.Username)
	if req.Router == "" || req.Username == "" {
		writeJSON(w, http.StatusBadRequest, map[string]any{"ok": false, "error": "router and username are required"})
		return targetRequest{}, false
	}

	return req, true
}

// routerError maps engine failures to HTTP status codes.
func (s *Server) routerError(w http.ResponseWriter, err error) {
	status := http.StatusBadGateway

	switch {
	case errors.Is(err, engine.ErrNotFound):
		status = http.StatusNotFound
	case errors.Is(err, engine.ErrSweepBusy):
		status = http.StatusConflict
	case errors.Is(err, context.DeadlineExceeded):
		status = http.StatusGatewayTimeout
	}

	s.log.Warn("control request failed", "status", status, "error", err)
	writeJSON(w, status, map[string]any{"ok": false, "error": err.Error()})
}

func (s *Server) authorize(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		provided := r.Header.Get("X-Engine-Token")
		if provided == "" {
			auth := r.Header.Get("Authorization")
			if len(auth) > 7 && strings.EqualFold(auth[:7], "Bearer ") {
				provided = strings.TrimSpace(auth[7:])
			}
		}

		if subtle.ConstantTimeCompare([]byte(provided), s.token) != 1 {
			writeJSON(w, http.StatusUnauthorized, map[string]any{"ok": false, "error": "unauthorized"})
			return
		}

		next.ServeHTTP(w, r)
	})
}

type statusRecorder struct {
	http.ResponseWriter
	status int
}

func (r *statusRecorder) WriteHeader(status int) {
	r.status = status
	r.ResponseWriter.WriteHeader(status)
}

func (s *Server) withLogging(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		started := time.Now()
		recorder := &statusRecorder{ResponseWriter: w, status: http.StatusOK}

		next.ServeHTTP(recorder, r)

		s.log.Debug("http request",
			"method", r.Method,
			"path", r.URL.Path,
			"status", recorder.status,
			"duration", time.Since(started).String())
	})
}

func writeJSON(w http.ResponseWriter, status int, body any) {
	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	w.Header().Set("Cache-Control", "no-store")
	w.WriteHeader(status)
	_ = json.NewEncoder(w).Encode(body)
}
