package engine

import (
	"testing"
	"time"

	"github.com/codepagol/isp/backend/internal/store"
)

func ptr[T any](v T) *T { return &v }

func TestEvaluatePPPRules(t *testing.T) {
	tz := time.UTC
	now := time.Date(2026, time.November, 5, 10, 0, 0, 0, tz)

	base := store.PPPCandidate{
		CustomerUniqueID: "C-1",
		Username:         "ppp1",
		RouterName:       "core",
		MonthlyRent:      1000,
		AutoDisableMonth: ptr(1),
		AutoDisableDate:  ptr(time.Date(2026, time.November, 1, 0, 0, 0, 0, tz)),
	}
	settings := store.Settings{DisableCheckNo: 1, DisableCheckDays: 0}

	t.Run("due above total bill disables on auto-disable date", func(t *testing.T) {
		c := base
		c.DueAmount = 1500

		got := EvaluatePPP(c, settings, now, tz)
		if !got.Disable || got.Reason != "Auto Disable for Due" {
			t.Fatalf("got %+v", got)
		}
	})

	t.Run("small due never reaches the grace-limit rule", func(t *testing.T) {
		c := base
		c.DueAmount = 500

		if got := EvaluatePPP(c, settings, now, tz); got.Disable {
			t.Fatalf("expected no disable on the auto-disable date, got %+v", got)
		}

		// PHP rewrites auto_disable_date into the current month, so the
		// disable-limit (auto_disable_date + auto_disable_month) rolls forward
		// with it. Rule 3 is therefore preserved for parity but not reachable
		// for a small due while the normalization applies.
		later := time.Date(2026, time.December, 5, 10, 0, 0, 0, tz)
		if got := EvaluatePPP(c, settings, later, tz); got.Disable {
			t.Fatalf("expected no disable a month later, got %+v", got)
		}
	})

	t.Run("previous due disables on the exact previous-due date", func(t *testing.T) {
		c := base
		c.DueAmount = 1500
		c.PreviousDue = 800
		c.AutoDisableDate = ptr(time.Date(2026, time.November, 5, 0, 0, 0, 0, tz))

		got := EvaluatePPP(c, settings, now, tz)
		if !got.Disable || got.Reason != "Auto Disable for Previous Due" {
			t.Fatalf("got %+v", got)
		}
	})

	t.Run("disable limit stops repeated suspensions", func(t *testing.T) {
		c := base
		c.DueAmount = 1500
		c.DisableCount = 2

		limited := store.Settings{DisableCheckNo: 2, DisableCheckDays: 3}
		if got := EvaluatePPP(c, limited, now, tz); got.Disable {
			t.Fatalf("expected no disable at the limit, got %+v", got)
		}

		c.DisableCount = 1
		if got := EvaluatePPP(c, limited, now, tz); !got.Disable {
			t.Fatalf("expected disable under the limit, got %+v", got)
		}
	})

	t.Run("missing auto disable date never disables", func(t *testing.T) {
		c := base
		c.AutoDisableDate = nil
		c.DueAmount = 5000

		if got := EvaluatePPP(c, settings, now, tz); got.Disable {
			t.Fatalf("expected no disable, got %+v", got)
		}
	})
}

func TestVoucherExpiry(t *testing.T) {
	tz := time.FixedZone("Asia/Dhaka", 6*60*60)
	firstLogin := time.Date(2026, time.November, 5, 10, 0, 0, 0, time.UTC)

	got, err := VoucherExpiry(firstLogin, "1h30m", tz)
	if err != nil {
		t.Fatalf("VoucherExpiry: %v", err)
	}

	want := firstLogin.Add(90 * time.Minute)
	if !got.Equal(want) {
		t.Fatalf("got %v, want %v", got, want)
	}

	if _, err := VoucherExpiry(firstLogin, "garbage", tz); err == nil {
		t.Fatal("expected error for invalid validity")
	}
}
