package mikrotik

import (
	"testing"
	"time"
)

func TestParseDuration(t *testing.T) {
	cases := []struct {
		in      string
		want    time.Duration
		wantErr bool
	}{
		{in: "1h", want: time.Hour},
		{in: "30m", want: 30 * time.Minute},
		{in: "45s", want: 45 * time.Second},
		{in: "2d", want: 48 * time.Hour},
		{in: "1w", want: 7 * 24 * time.Hour},
		{in: "1d12h30m", want: 36*time.Hour + 30*time.Minute},
		{in: "1d12h30m5s", want: 36*time.Hour + 30*time.Minute + 5*time.Second},
		{in: "00:06:00", want: 6 * time.Minute},
		{in: "01:02:03", want: time.Hour + 2*time.Minute + 3*time.Second},
		{in: "05:30", want: 5*time.Minute + 30*time.Second},
		{in: "1w2d03:04:05", want: 7*24*time.Hour + 2*24*time.Hour + 3*time.Hour + 4*time.Minute + 5*time.Second},
		{in: "0s", want: 0}, // explicit zero is valid, matching PHP
		{in: "", wantErr: true},
		{in: "abc", wantErr: true},
	}

	for _, tc := range cases {
		t.Run(tc.in, func(t *testing.T) {
			got, err := ParseDuration(tc.in)
			if tc.wantErr {
				if err == nil {
					t.Fatalf("expected error, got %v", got)
				}
				return
			}
			if err != nil {
				t.Fatalf("unexpected error: %v", err)
			}
			if got != tc.want {
				t.Fatalf("got %v, want %v", got, tc.want)
			}
		})
	}
}

func TestSplitRateLimit(t *testing.T) {
	rx, tx, err := SplitRateLimit("10M/10M")
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if rx != "10M" || tx != "10M" {
		t.Fatalf("got %q/%q", rx, tx)
	}

	if _, _, err := SplitRateLimit("10M"); err == nil {
		t.Fatal("expected error for missing tx component")
	}
}
