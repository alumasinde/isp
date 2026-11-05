// Package mikrotik ports the RouterOS value parsing that lived in PHP
// (HotspotVoucher::parseMikrotikDuration / parseUptimeToSeconds).
package mikrotik

import (
	"fmt"
	"regexp"
	"strconv"
	"strings"
	"time"
)

var durationPair = regexp.MustCompile(`(\d+)\s*([wdhms])`)

// ParseDuration converts a RouterOS duration ("1h", "2d", "30m", "1d12h30m",
// "1w2d03:04:05", "00:06:00") into a time.Duration.
func ParseDuration(value string) (time.Duration, error) {
	original := value
	s := strings.TrimSpace(value)
	if s == "" {
		return 0, fmt.Errorf("empty MikroTik duration")
	}

	var total time.Duration

	// A trailing clock segment may follow unit pairs: "1d12h30m" and
	// "1w2d03:04:05" are both valid RouterOS output.
	if idx := strings.Index(s, ":"); idx >= 0 {
		clockStart := idx
		for clockStart > 0 && isDigit(s[clockStart-1]) {
			clockStart--
		}
		clock, err := parseClock(s[clockStart:])
		if err != nil {
			return 0, fmt.Errorf("invalid MikroTik duration %q: %w", original, err)
		}
		total += clock
		s = s[:clockStart]
	}

	matched := false
	for _, m := range durationPair.FindAllStringSubmatch(strings.ToLower(s), -1) {
		n, err := strconv.Atoi(m[1])
		if err != nil {
			return 0, fmt.Errorf("invalid MikroTik duration %q", original)
		}
		matched = true
		switch m[2] {
		case "w":
			total += time.Duration(n) * 7 * 24 * time.Hour
		case "d":
			total += time.Duration(n) * 24 * time.Hour
		case "h":
			total += time.Duration(n) * time.Hour
		case "m":
			total += time.Duration(n) * time.Minute
		case "s":
			total += time.Duration(n) * time.Second
		}
	}

	if !matched && total == 0 {
		return 0, fmt.Errorf("invalid MikroTik duration %q", original)
	}

	return total, nil
}

// parseClock handles "hh:mm:ss", "mm:ss" and "dd:hh:mm:ss"-free clock values.
func parseClock(s string) (time.Duration, error) {
	parts := strings.Split(strings.TrimSpace(s), ":")
	if len(parts) < 2 || len(parts) > 3 {
		return 0, fmt.Errorf("unsupported clock format")
	}

	numbers := make([]int, len(parts))
	for i, p := range parts {
		n, err := strconv.Atoi(strings.TrimSpace(p))
		if err != nil || n < 0 {
			return 0, fmt.Errorf("unsupported clock format")
		}
		numbers[i] = n
	}

	if len(numbers) == 3 {
		return time.Duration(numbers[0])*time.Hour +
			time.Duration(numbers[1])*time.Minute +
			time.Duration(numbers[2])*time.Second, nil
	}
	return time.Duration(numbers[0])*time.Minute +
		time.Duration(numbers[1])*time.Second, nil
}

func isDigit(b byte) bool { return b >= '0' && b <= '9' }

// SplitRateLimit validates a RouterOS rate-limit string such as "10M/10M".
// It is used only to reject malformed values before they reach a router.
func SplitRateLimit(value string) (rx, tx string, err error) {
	parts := strings.Split(strings.TrimSpace(value), "/")
	if len(parts) != 2 || parts[0] == "" || parts[1] == "" {
		return "", "", fmt.Errorf("invalid rate-limit %q, expected rx/tx such as 10M/10M", value)
	}
	return parts[0], parts[1], nil
}
