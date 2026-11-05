package engine

import (
	"time"

	"github.com/codepagol/isp/backend/internal/mikrotik"
	"github.com/codepagol/isp/backend/internal/store"
)

// PPCDecision is the outcome of evaluating one customer's billing state.
type PPCDecision struct {
	Disable bool
	Reason  string
}

// EvaluatePPP ports the three disable rules from
// ScheduledTasksController::userDisable.
//
// The rules are intentionally identical to PHP, including its quirks: a
// customer is only considered while disable_count < disable_check_no, and a
// billing row with paid_amount = 0 counts as unpaid.
func EvaluatePPP(c store.PPPCandidate, settings store.Settings, now time.Time, tz *time.Location) PPCDecision {
	if c.AutoDisableDate == nil {
		return PPCDecision{}
	}
	if c.DisableCount >= settings.DisableCheckNo {
		return PPCDecision{}
	}

	today := startOfDay(now, tz)

	baseDate := c.AutoDisableDate.In(tz)
	if baseDate.Before(now.In(tz)) && (baseDate.Month() != today.Month() || baseDate.Year() != today.Year()) {
		baseDate = time.Date(today.Year(), today.Month(), baseDate.Day(),
			baseDate.Hour(), baseDate.Minute(), baseDate.Second(), 0, tz)
	}

	var autoDisableDate time.Time
	if settings.DisableCheckNo <= 1 {
		autoDisableDate = startOfDay(baseDate, tz)
	} else {
		autoDisableDate = startOfDay(baseDate.AddDate(0, 0, settings.DisableCheckDays*c.DisableCount), tz)
	}

	previousDueDisableDate := time.Date(today.Year(), today.Month(), baseDate.Day(), 0, 0, 0, 0, tz)
	if settings.DisableCheckNo > 1 {
		previousDueDisableDate = previousDueDisableDate.AddDate(0, 0, settings.DisableCheckDays*c.DisableCount)
	}

	months := 0
	if c.AutoDisableMonth != nil {
		months = *c.AutoDisableMonth
	}
	disableLimitDate := autoDisableDate.AddDate(0, months, 0)

	totalBill := (c.MonthlyRent + c.AdditionalCharge + c.VAT) * float64(months)

	// Rule 1: previous due is cleared on the exact previous-due date.
	if c.PreviousDue > 0 && c.DueAmount > totalBill && sameDay(today, previousDueDisableDate) {
		return PPCDecision{Disable: true, Reason: "Auto Disable for Previous Due"}
	}

	// Rule 2: current due exceeds the full billed amount.
	if c.DueAmount > totalBill && !today.Before(autoDisableDate) {
		return PPCDecision{Disable: true, Reason: "Auto Disable for Due"}
	}

	// Rule 3: small due, only after the full grace period. PHP shifts
	// auto_disable_date into the current month before computing the limit, so
	// the limit normally stays in the future; the rule is kept verbatim anyway
	// so the two implementations cannot drift.
	if c.DueAmount > 0 && c.DueAmount <= totalBill && !today.Before(disableLimitDate) {
		return PPCDecision{Disable: true, Reason: "Auto Disable Date reached"}
	}

	return PPCDecision{}
}

// VoucherExpiry computes the absolute expiry of a realtime hotspot voucher.
func VoucherExpiry(firstLogin time.Time, validity string, tz *time.Location) (time.Time, error) {
	d, err := mikrotik.ParseDuration(validity)
	if err != nil {
		return time.Time{}, err
	}
	return firstLogin.In(tz).Add(d), nil
}

func startOfDay(t time.Time, tz *time.Location) time.Time {
	t = t.In(tz)
	return time.Date(t.Year(), t.Month(), t.Day(), 0, 0, 0, 0, tz)
}

func sameDay(a, b time.Time) bool {
	return a.Year() == b.Year() && a.YearDay() == b.YearDay()
}
