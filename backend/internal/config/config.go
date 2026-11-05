// Package config loads the engine settings from the environment.
//
// It deliberately understands the same variable names Laravel already uses
// (DB_*, APP_KEY, APP_TIMEZONE) so the engine can be pointed at the existing
// .env without a second configuration file.
package config

import (
	"bufio"
	"errors"
	"fmt"
	"os"
	"strconv"
	"strings"
	"time"

	"github.com/go-sql-driver/mysql"
)

// Config is the fully validated engine configuration.
type Config struct {
	// HTTPAddr is the loopback address the internal API listens on.
	HTTPAddr string
	// Token authenticates callers of the internal API (Bearer / X-Engine-Token).
	Token string
	// DBDSN is a go-sql-driver/mysql DSN built from the DB_* variables.
	DBDSN string
	// AppKey is Laravel's APP_KEY, used to decrypt router credentials.
	AppKey string
	// Timezone is the billing timezone (APP_TIMEZONE).
	Timezone *time.Location
	// SweepInterval is how often the expiry engine runs.
	SweepInterval time.Duration
	// RouterTimeout bounds a single RouterOS API exchange.
	RouterTimeout time.Duration
	// Concurrency caps how many routers are processed at the same time.
	Concurrency int
	// ExpiredProfile is the PPP profile used for soft suspension.
	ExpiredProfile string
	// RunOnStart performs a sweep immediately at boot.
	RunOnStart bool
	// PPPAutoDisable enables the billing-driven PPP suspension sweep.
	PPPAutoDisable bool
	// LogLevel is one of debug, info, warn, error.
	LogLevel string
}

// Load reads .env (if present) and the process environment.
func Load() (*Config, error) {
	loadDotEnv(".env")

	loc, err := time.LoadLocation(env("APP_TIMEZONE", "Asia/Dhaka"))
	if err != nil {
		return nil, fmt.Errorf("invalid APP_TIMEZONE: %w", err)
	}

	dsn, err := databaseDSN(loc)
	if err != nil {
		return nil, err
	}

	cfg := &Config{
		HTTPAddr:       env("ENGINE_HTTP_ADDR", "127.0.0.1:8080"),
		Token:          strings.TrimSpace(os.Getenv("ENGINE_TOKEN")),
		DBDSN:          dsn,
		AppKey:         strings.TrimSpace(os.Getenv("APP_KEY")),
		Timezone:       loc,
		SweepInterval:  durationEnv("ENGINE_SWEEP_INTERVAL", 60*time.Second),
		RouterTimeout:  durationEnv("ENGINE_ROUTER_TIMEOUT", 5*time.Second),
		Concurrency:    intEnv("ENGINE_CONCURRENCY", 8),
		ExpiredProfile: env("ENGINE_EXPIRED_PROFILE", "Expired"),
		RunOnStart:     boolEnv("ENGINE_RUN_ON_START", true),
		PPPAutoDisable: boolEnv("ENGINE_PPP_AUTODISABLE", false),
		LogLevel:       strings.ToLower(env("ENGINE_LOG_LEVEL", "info")),
	}

	if cfg.AppKey == "" {
		return nil, errors.New("APP_KEY is required: router credentials in router_lists are encrypted with it")
	}
	if cfg.Token == "" {
		return nil, errors.New("ENGINE_TOKEN is required: it protects the internal control API")
	}
	if cfg.SweepInterval < time.Second {
		return nil, errors.New("ENGINE_SWEEP_INTERVAL must be at least 1s")
	}
	if cfg.RouterTimeout <= 0 {
		return nil, errors.New("ENGINE_ROUTER_TIMEOUT must be positive")
	}
	if cfg.Concurrency < 1 {
		cfg.Concurrency = 1
	}

	switch cfg.LogLevel {
	case "debug", "info", "warn", "error":
	default:
		return nil, fmt.Errorf("ENGINE_LOG_LEVEL must be debug, info, warn or error (got %q)", cfg.LogLevel)
	}

	return cfg, nil
}

// databaseDSN builds a MySQL DSN from DB_DSN or the Laravel DB_* variables.
func databaseDSN(loc *time.Location) (string, error) {
	if dsn := strings.TrimSpace(os.Getenv("DB_DSN")); dsn != "" {
		cfg, err := mysql.ParseDSN(dsn)
		if err != nil {
			return "", fmt.Errorf("invalid DB_DSN: %w", err)
		}
		cfg.ParseTime = true
		cfg.Loc = loc
		return cfg.FormatDSN(), nil
	}

	name := strings.TrimSpace(os.Getenv("DB_DATABASE"))
	if name == "" {
		return "", errors.New("DB_DATABASE (or DB_DSN) is required")
	}

	cfg := mysql.NewConfig()
	cfg.User = env("DB_USERNAME", "root")
	cfg.Passwd = os.Getenv("DB_PASSWORD")
	cfg.Net = "tcp"
	cfg.Addr = env("DB_HOST", "127.0.0.1") + ":" + env("DB_PORT", "3306")
	cfg.DBName = name
	cfg.ParseTime = true
	cfg.Loc = loc
	cfg.Timeout = 5 * time.Second
	cfg.ReadTimeout = 30 * time.Second
	cfg.WriteTimeout = 30 * time.Second
	cfg.Params = map[string]string{"charset": "utf8mb4"}

	return cfg.FormatDSN(), nil
}

// loadDotEnv fills os.Environ from path without overriding real environment
// variables, so `ENGINE_TOKEN=x ./isp-engine` always wins over the file.
func loadDotEnv(path string) {
	f, err := os.Open(path)
	if err != nil {
		return
	}
	defer f.Close()

	scanner := bufio.NewScanner(f)
	for scanner.Scan() {
		line := strings.TrimSpace(scanner.Text())
		if line == "" || strings.HasPrefix(line, "#") {
			continue
		}
		line = strings.TrimPrefix(line, "export ")
		key, value, ok := strings.Cut(line, "=")
		if !ok {
			continue
		}
		key = strings.TrimSpace(key)
		if key == "" || os.Getenv(key) != "" {
			continue
		}
		value = strings.TrimSpace(value)
		if len(value) >= 2 {
			if (value[0] == '"' && value[len(value)-1] == '"') || (value[0] == '\'' && value[len(value)-1] == '\'') {
				value = value[1 : len(value)-1]
			}
		}
		_ = os.Setenv(key, value)
	}
}

func env(key, fallback string) string {
	if v := strings.TrimSpace(os.Getenv(key)); v != "" {
		return v
	}
	return fallback
}

func intEnv(key string, fallback int) int {
	v, err := strconv.Atoi(strings.TrimSpace(os.Getenv(key)))
	if err != nil {
		return fallback
	}
	return v
}

func boolEnv(key string, fallback bool) bool {
	raw := strings.ToLower(strings.TrimSpace(os.Getenv(key)))
	switch raw {
	case "1", "true", "yes", "on":
		return true
	case "0", "false", "no", "off":
		return false
	default:
		return fallback
	}
}

func durationEnv(key string, fallback time.Duration) time.Duration {
	raw := strings.TrimSpace(os.Getenv(key))
	if raw == "" {
		return fallback
	}
	d, err := time.ParseDuration(raw)
	if err != nil {
		return fallback
	}
	return d
}
