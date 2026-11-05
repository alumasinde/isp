package laravelcrypt

import (
	"encoding/json"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

// fixtures are produced by PHP (see backend/internal/laravelcrypt/testdata)
// using real Laravel encrypt() semantics, so these tests prove interop with the
// PHP application rather than round-tripping our own code.
type fixtures struct {
	AppKey   string            `json:"app_key"`
	Values   map[string]string `json:"values"`
	Payloads map[string]string `json:"payloads"`
}

func loadFixtures(t *testing.T) fixtures {
	t.Helper()

	raw, err := os.ReadFile(filepath.Join("testdata", "laravel_fixtures.json"))
	if err != nil {
		t.Fatalf("read fixtures: %v", err)
	}

	var f fixtures
	if err := json.Unmarshal(raw, &f); err != nil {
		t.Fatalf("parse fixtures: %v", err)
	}
	if f.AppKey == "" || len(f.Payloads) == 0 {
		t.Fatal("fixtures are incomplete")
	}
	if len(f.Payloads) != len(f.Values) {
		t.Fatalf("fixtures mismatch: %d payloads, %d values", len(f.Payloads), len(f.Values))
	}

	return f
}

func TestDecryptStringMatchesLaravel(t *testing.T) {
	f := loadFixtures(t)

	enc, err := New(f.AppKey)
	if err != nil {
		t.Fatalf("New: %v", err)
	}

	for name, payload := range f.Payloads {
		t.Run(name, func(t *testing.T) {
			got, err := enc.DecryptString(payload)
			if err != nil {
				t.Fatalf("DecryptString: %v", err)
			}
			if got != f.Values[name] {
				t.Fatalf("got %q, want %q", got, f.Values[name])
			}
		})
	}
}

func TestDecryptStringRejectsWrongKey(t *testing.T) {
	f := loadFixtures(t)

	// A different 32-byte key: MAC verification must fail for every payload.
	other, err := New("base64:AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8=")
	if err != nil {
		t.Fatalf("New: %v", err)
	}

	for name, payload := range f.Payloads {
		if _, err := other.DecryptString(payload); err == nil {
			t.Fatalf("%s: expected MAC mismatch, got nil", name)
		}
	}
}

func TestDecryptStringRejectsTamperedPayload(t *testing.T) {
	f := loadFixtures(t)
	enc, err := New(f.AppKey)
	if err != nil {
		t.Fatalf("New: %v", err)
	}

	for _, payload := range f.Payloads {
		tampered := payload[:len(payload)-8] + "AAAAAAA="
		if _, err := enc.DecryptString(tampered); err == nil {
			t.Fatal("expected error for tampered payload, got nil")
		}
	}
}

func TestDecryptOrRawFallsBackToPlaintext(t *testing.T) {
	f := loadFixtures(t)
	enc, err := New(f.AppKey)
	if err != nil {
		t.Fatalf("New: %v", err)
	}

	// Legacy rows were written before RouterList encrypted its attributes.
	if got := enc.DecryptOrRaw("192.168.88.1"); got != "192.168.88.1" {
		t.Fatalf("got %q, want the raw value", got)
	}
	if got := enc.DecryptOrRaw(""); got != "" {
		t.Fatalf("got %q, want empty", got)
	}
}

func TestNewRejectsUnsupportedKeyLength(t *testing.T) {
	cases := map[string]string{
		"too short":     "short",
		"16 byte key":   "base64:ASNFZ4mrze8BI0VniavN7w==",
		"invalid b64":   "base64:not-base64!!",
		"empty app key": "",
	}

	for name, key := range cases {
		if _, err := New(key); err == nil {
			t.Fatalf("%s: expected error", name)
		}
	}
}

func TestUnserializeScalarRejectsStructures(t *testing.T) {
	if _, err := unserializeScalar(`a:1:{i:0;s:1:"x";}`); err == nil {
		t.Fatal("expected error for serialized array")
	}
	if _, err := unserializeScalar(`s:99:"short";`); err == nil {
		t.Fatal("expected error for truncated string")
	}
	if got, err := unserializeScalar("N;"); err != nil || got != "" {
		t.Fatalf("N; should decode to empty, got %q err=%v", got, err)
	}
	if _, err := unserializeScalar(strings.Repeat("x", 4)); err == nil {
		t.Fatal("expected error for unknown payload")
	}
}
