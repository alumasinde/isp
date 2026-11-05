// Package store is the shared-database adapter between the Go engine and the
// Laravel application. Both processes read the same MySQL tables; the engine is
// the only writer for network state it owns (voucher expiry, suspension state).
package store

import (
	"context"
	"database/sql"
	"errors"
	"fmt"
	"strconv"
	"strings"
	"time"

	_ "github.com/go-sql-driver/mysql"

	"github.com/codepagol/isp/backend/internal/laravelcrypt"
)

// Router is one connected MikroTik from router_lists with credentials decrypted.
type Router struct {
	ID       int64
	Name     string
	Host     string
	Username string
	Password string
	APIPort  int
	SSHPort  int
}

// Voucher is a realtime hotspot voucher that may have expired.
type Voucher struct {
	ID               int64
	Code             string
	RouterName       string
	Username         string
	ValidityDuration string
	FirstLoginAt     time.Time
}

// PPPCandidate is one active customer joined with its PPP secret and billing.
type PPPCandidate struct {
	CustomerUniqueID string
	PPPUserID        int64
	DisableCount     int
	Username         string
	RouterName       string
	MonthlyRent      float64
	AdditionalCharge float64
	VAT              float64
	PreviousDue      float64
	DueAmount        float64
	AutoDisableDate  *time.Time
	AutoDisableMonth *int
}

// Settings are the main_site_data values the suspension rules depend on.
type Settings struct {
	DisableCheckNo   int
	DisableCheckDays int
	ExpiredProfile   string
}

// Store wraps the MySQL connection pool.
type Store struct {
	db  *sql.DB
	enc *laravelcrypt.Encrypter
}

// Open validates APP_KEY and prepares the MySQL pool.
func Open(dsn, appKey string) (*Store, error) {
	enc, err := laravelcrypt.New(appKey)
	if err != nil {
		return nil, err
	}

	db, err := sql.Open("mysql", dsn)
	if err != nil {
		return nil, fmt.Errorf("open mysql: %w", err)
	}
	db.SetMaxOpenConns(10)
	db.SetMaxIdleConns(5)
	db.SetConnMaxLifetime(30 * time.Minute)

	return &Store{db: db, enc: enc}, nil
}

// Close releases the pool.
func (s *Store) Close() error { return s.db.Close() }

// Ping verifies database connectivity for /readyz.
func (s *Store) Ping(ctx context.Context) error { return s.db.PingContext(ctx) }

const routerColumns = `id, router_name, ip_address, username, password, COALESCE(ssh_port, ''), COALESCE(api_port, '')`

// ConnectedRouters returns routers the application has marked connected.
func (s *Store) ConnectedRouters(ctx context.Context) ([]Router, error) {
	rows, err := s.db.QueryContext(ctx,
		`SELECT `+routerColumns+` FROM router_lists WHERE action = 'connected' ORDER BY router_name`)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	var routers []Router
	for rows.Next() {
		router, err := s.scanRouter(rows)
		if err != nil {
			return nil, err
		}
		routers = append(routers, router)
	}
	return routers, rows.Err()
}

// RouterByName returns one connected router or nil.
func (s *Store) RouterByName(ctx context.Context, name string) (*Router, error) {
	row := s.db.QueryRowContext(ctx,
		`SELECT `+routerColumns+` FROM router_lists WHERE router_name = ? AND action = 'connected' LIMIT 1`, name)

	router, err := s.scanRouter(row)
	if errors.Is(err, sql.ErrNoRows) {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	return &router, nil
}

type rowScanner interface {
	Scan(dest ...any) error
}

// scanRouter decrypts the Laravel-encrypted credential columns.
func (s *Store) scanRouter(sc rowScanner) (Router, error) {
	var (
		r                                  Router
		host, user, password, ssh, apiPort string
	)
	if err := sc.Scan(&r.ID, &r.Name, &host, &user, &password, &ssh, &apiPort); err != nil {
		return Router{}, err
	}

	r.Host = strings.TrimSpace(s.enc.DecryptOrRaw(host))
	r.Username = strings.TrimSpace(s.enc.DecryptOrRaw(user))
	r.Password = s.enc.DecryptOrRaw(password)
	r.APIPort = atoiOrZero(s.enc.DecryptOrRaw(apiPort))
	r.SSHPort = atoiOrZero(s.enc.DecryptOrRaw(ssh))

	return r, nil
}

// RealtimeVouchers returns used realtime vouchers, i.e. the candidates whose
// first_login_at + validity_duration may now be in the past.
func (s *Store) RealtimeVouchers(ctx context.Context) ([]Voucher, error) {
	rows, err := s.db.QueryContext(ctx, `
		SELECT id, COALESCE(code, ''), router_name, COALESCE(username, ''), validity_duration, first_login_at
		FROM hotspot_vouchers
		WHERE validity_type = 'realtime'
		  AND status = 'used'
		  AND first_login_at IS NOT NULL
		  AND validity_duration IS NOT NULL
		  AND validity_duration <> ''
		  AND router_name IS NOT NULL
		  AND router_name <> ''
		ORDER BY id`)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	var vouchers []Voucher
	for rows.Next() {
		var v Voucher
		if err := rows.Scan(&v.ID, &v.Code, &v.RouterName, &v.Username, &v.ValidityDuration, &v.FirstLoginAt); err != nil {
			return nil, err
		}
		if v.Username == "" {
			continue
		}
		vouchers = append(vouchers, v)
	}
	return vouchers, rows.Err()
}

// DeleteVoucher removes an expired voucher, matching the PHP command which
// deletes the row after removing the user from the router.
func (s *Store) DeleteVoucher(ctx context.Context, id int64) error {
	_, err := s.db.ExecContext(ctx, `DELETE FROM hotspot_vouchers WHERE id = ?`, id)
	return err
}

// DeleteVoucherByUser removes a voucher by router + username.
func (s *Store) DeleteVoucherByUser(ctx context.Context, routerName, username string) error {
	_, err := s.db.ExecContext(ctx,
		`DELETE FROM hotspot_vouchers WHERE router_name = ? AND username = ?`, routerName, username)
	return err
}

// PPPCandidates returns active customers that have a PPP secret, an automatic
// disable date and an unpaid, auto-disable-enabled billing row.
func (s *Store) PPPCandidates(ctx context.Context) ([]PPPCandidate, error) {
	rows, err := s.db.QueryContext(ctx, `
		SELECT c.customer_unique_id,
		       c.ppp_user_id,
		       COALESCE(c.disable_count, 0),
		       s.username,
		       s.router_name,
		       b.monthly_rent,
		       b.additional_charge,
		       b.vat,
		       b.previous_due,
		       b.due_amount,
		       b.auto_disable_date,
		       b.auto_disable_month
		FROM customers_infos c
		JOIN p_p_p_secrets s ON s.id = c.ppp_user_id
		JOIN billing_infos b ON b.id = (
		    SELECT MAX(b2.id) FROM billing_infos b2
		    WHERE b2.customer_bill_unique_id = c.customer_unique_id
		)
		WHERE c.status = 'active'
		  AND c.deleted_at IS NULL
		  AND c.ppp_user_id IS NOT NULL
		  AND s.username IS NOT NULL AND s.username <> ''
		  AND s.router_name IS NOT NULL AND s.router_name <> ''
		  AND b.auto_disable = 1
		  AND b.paid_amount = 0
		  AND b.auto_disable_date IS NOT NULL
		ORDER BY c.customer_unique_id`)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	var candidates []PPPCandidate
	for rows.Next() {
		var (
			c     PPPCandidate
			date  sql.NullTime
			month sql.NullInt64
		)
		if err := rows.Scan(
			&c.CustomerUniqueID, &c.PPPUserID, &c.DisableCount, &c.Username, &c.RouterName,
			&c.MonthlyRent, &c.AdditionalCharge, &c.VAT, &c.PreviousDue, &c.DueAmount,
			&date, &month,
		); err != nil {
			return nil, err
		}
		if date.Valid {
			t := date.Time
			c.AutoDisableDate = &t
		}
		if month.Valid {
			m := int(month.Int64)
			c.AutoDisableMonth = &m
		}
		candidates = append(candidates, c)
	}
	return candidates, rows.Err()
}

// MarkCustomerDisabled applies the suspension to the shared database. The
// update is conditional on status='active' so the PHP cron and this engine can
// never double-increment disable_count.
func (s *Store) MarkCustomerDisabled(ctx context.Context, c PPPCandidate, mode, reason string) (bool, error) {
	tx, err := s.db.BeginTx(ctx, nil)
	if err != nil {
		return false, err
	}
	defer func() { _ = tx.Rollback() }()

	now := time.Now()

	res, err := tx.ExecContext(ctx, `
		UPDATE customers_infos
		SET status = 'disable', disable_count = COALESCE(disable_count, 0) + 1, updated_at = ?
		WHERE customer_unique_id = ? AND status = 'active' AND deleted_at IS NULL`,
		now, c.CustomerUniqueID)
	if err != nil {
		return false, err
	}

	affected, err := res.RowsAffected()
	if err != nil {
		return false, err
	}
	if affected == 0 {
		return false, nil
	}

	if _, err := tx.ExecContext(ctx,
		`UPDATE p_p_p_secrets SET status = 'disable', updated_at = ? WHERE id = ?`,
		now, c.PPPUserID); err != nil {
		return false, err
	}

	if err := tx.Commit(); err != nil {
		return false, err
	}

	// Logging is best-effort: the router action already happened, so a missing
	// notification row must not turn a successful suspension into a failure.
	_ = s.LogNotification(ctx,
		"Disconnection Success",
		fmt.Sprintf("%s (%s) - (%s) [engine:%s]", c.CustomerUniqueID, c.Username, reason, mode),
		"User disabled by network engine",
		"Mikrotik Command")

	return true, nil
}

// Settings reads the auto-disable settings, falling back to PHP's defaults when
// main_site_data is absent or empty.
func (s *Store) Settings(ctx context.Context) (Settings, error) {
	settings := Settings{DisableCheckNo: 1, DisableCheckDays: 0, ExpiredProfile: "Expired"}

	rows, err := s.db.QueryContext(ctx, `
		SELECT type, value FROM main_site_data
		WHERE type IN ('disable_check_no', 'disable_check_days', 'expired_profile_name')`)
	if err != nil {
		// A fresh install may not have the settings table yet.
		return settings, nil
	}
	defer rows.Close()

	for rows.Next() {
		var key, value string
		if err := rows.Scan(&key, &value); err != nil {
			return settings, err
		}
		value = strings.Trim(strings.TrimSpace(value), `"`)
		switch key {
		case "disable_check_no":
			settings.DisableCheckNo = atoiOrDefault(value, settings.DisableCheckNo)
		case "disable_check_days":
			settings.DisableCheckDays = atoiOrDefault(value, settings.DisableCheckDays)
		case "expired_profile_name":
			if value != "" {
				settings.ExpiredProfile = value
			}
		}
	}
	if err := rows.Err(); err != nil {
		return settings, err
	}

	if settings.DisableCheckNo < 1 {
		settings.DisableCheckNo = 1
	}
	return settings, nil
}

// LogNotification appends a row the admin panel already knows how to display.
func (s *Store) LogNotification(ctx context.Context, title, message, status, kind string) error {
	now := time.Now()
	_, err := s.db.ExecContext(ctx, `
		INSERT INTO notification_logs (title, message, status, type, created_at, updated_at)
		VALUES (?, ?, ?, ?, ?, ?)`,
		title, message, status, kind, now, now)
	return err
}

func atoiOrZero(raw string) int { return atoiOrDefault(raw, 0) }

func atoiOrDefault(raw string, fallback int) int {
	n, err := strconv.Atoi(strings.TrimSpace(raw))
	if err != nil {
		return fallback
	}
	return n
}
