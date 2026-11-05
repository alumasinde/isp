// Package laravelcrypt decrypts values written by Laravel's Crypt facade.
//
// router_lists.ip_address, username, password, ssh_port and api_port are all
// stored through RouterList's mutators, i.e. `encrypt($value)`. Laravel's
// payload is:
//
//	base64(json{iv: base64(16B), value: base64(AES-256-CBC(serialize(v))),
//	           mac: hmac_sha256(base64iv + base64value, key), tag: ""})
//
// Reading it from Go requires the same APP_KEY, the same MAC check and a PHP
// scalar unserializer, which is what this package provides.
package laravelcrypt

import (
	"crypto/aes"
	"crypto/cipher"
	"crypto/hmac"
	"crypto/sha256"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"strconv"
	"strings"
)

// ErrNotEncrypted reports a value that is not a Laravel payload. Callers use it
// to fall back to the raw value, matching RouterList::decryptValue().
var ErrNotEncrypted = errors.New("value is not a Laravel encrypted payload")

type payload struct {
	IV    string `json:"iv"`
	Value string `json:"value"`
	MAC   string `json:"mac"`
	Tag   string `json:"tag"`
}

// Encrypter holds the decoded APP_KEY.
type Encrypter struct {
	key []byte
}

// New builds an Encrypter from Laravel's APP_KEY ("base64:..." or raw).
func New(appKey string) (*Encrypter, error) {
	appKey = strings.TrimSpace(appKey)
	var key []byte
	if strings.HasPrefix(appKey, "base64:") {
		decoded, err := base64.StdEncoding.DecodeString(strings.TrimPrefix(appKey, "base64:"))
		if err != nil {
			return nil, fmt.Errorf("APP_KEY base64 decode: %w", err)
		}
		key = decoded
	} else {
		key = []byte(appKey)
	}

	// Laravel's default cipher is AES-256-CBC, which requires a 32-byte key.
	// Shorter keys would silently select AES-128/192 here while PHP keeps
	// zero-padding to 256 bits, producing unreadable payloads, so reject them.
	if len(key) != 32 {
		return nil, fmt.Errorf("APP_KEY must decode to 32 bytes for AES-256-CBC (got %d)", len(key))
	}

	return &Encrypter{key: key}, nil
}

// DecryptString decrypts a Laravel payload and returns the scalar it contains.
func (e *Encrypter) DecryptString(encoded string) (string, error) {
	raw, err := base64.StdEncoding.DecodeString(strings.TrimSpace(encoded))
	if err != nil {
		return "", fmt.Errorf("%w: %v", ErrNotEncrypted, err)
	}

	var p payload
	if err := json.Unmarshal(raw, &p); err != nil {
		return "", fmt.Errorf("%w: %v", ErrNotEncrypted, err)
	}
	if p.IV == "" || p.Value == "" {
		return "", ErrNotEncrypted
	}

	// Laravel verifies the MAC over the base64 strings, not the raw bytes.
	mac := hmac.New(sha256.New, e.key)
	mac.Write([]byte(p.IV))
	mac.Write([]byte(p.Value))
	expected := hex.EncodeToString(mac.Sum(nil))
	if !hmac.Equal([]byte(expected), []byte(p.MAC)) {
		return "", errors.New("payload MAC mismatch: APP_KEY does not match the PHP application")
	}

	iv, err := base64.StdEncoding.DecodeString(p.IV)
	if err != nil {
		return "", fmt.Errorf("iv decode: %w", err)
	}
	ciphertext, err := base64.StdEncoding.DecodeString(p.Value)
	if err != nil {
		return "", fmt.Errorf("value decode: %w", err)
	}

	block, err := aes.NewCipher(e.key)
	if err != nil {
		return "", err
	}
	if len(iv) != block.BlockSize() {
		return "", fmt.Errorf("invalid iv length %d", len(iv))
	}
	if len(ciphertext) == 0 || len(ciphertext)%block.BlockSize() != 0 {
		return "", errors.New("ciphertext is not a multiple of the block size")
	}

	plain := make([]byte, len(ciphertext))
	cipher.NewCBCDecrypter(block, iv).CryptBlocks(plain, ciphertext)

	plain, err = unpadPKCS7(plain, block.BlockSize())
	if err != nil {
		return "", err
	}

	return unserializeScalar(string(plain))
}

// DecryptOrRaw mirrors RouterList::decryptValue(): encrypted values are
// decrypted, legacy plaintext values are returned unchanged.
func (e *Encrypter) DecryptOrRaw(value string) string {
	if strings.TrimSpace(value) == "" {
		return value
	}
	plain, err := e.DecryptString(value)
	if err != nil {
		return value
	}
	return plain
}

func unpadPKCS7(data []byte, blockSize int) ([]byte, error) {
	if len(data) == 0 {
		return nil, errors.New("empty plaintext")
	}
	pad := int(data[len(data)-1])
	if pad == 0 || pad > blockSize || pad > len(data) {
		return nil, errors.New("invalid padding")
	}
	for _, b := range data[len(data)-pad:] {
		if int(b) != pad {
			return nil, errors.New("invalid padding")
		}
	}
	return data[:len(data)-pad], nil
}

// unserializeScalar converts PHP serialize() output for a single scalar into a
// string. RouterList only ever encrypts strings and integers.
func unserializeScalar(s string) (string, error) {
	s = strings.TrimSpace(s)

	switch {
	case s == "N;":
		return "", nil

	case strings.HasPrefix(s, "s:"):
		rest := s[2:]
		colon := strings.Index(rest, ":")
		if colon < 0 {
			return "", fmt.Errorf("malformed serialized string: %q", s)
		}
		length, err := strconv.Atoi(rest[:colon])
		if err != nil || length < 0 {
			return "", fmt.Errorf("malformed serialized string length: %q", s)
		}
		rest = rest[colon+1:]
		if !strings.HasPrefix(rest, `"`) {
			return "", fmt.Errorf("malformed serialized string body: %q", s)
		}
		body := rest[1:]
		if len(body) < length+2 {
			return "", fmt.Errorf("truncated serialized string: %q", s)
		}
		value := body[:length]
		if tail := body[length:]; tail != `";` {
			return "", fmt.Errorf("malformed serialized string terminator: %q", s)
		}
		return value, nil

	case strings.HasPrefix(s, "i:"), strings.HasPrefix(s, "d:"):
		rest := strings.TrimSuffix(s[2:], ";")
		if _, err := strconv.ParseFloat(rest, 64); err != nil {
			return "", fmt.Errorf("malformed serialized number: %q", s)
		}
		return rest, nil

	case strings.HasPrefix(s, "b:"):
		rest := strings.TrimSuffix(s[2:], ";")
		if rest == "1" {
			return "1", nil
		}
		if rest == "0" {
			return "0", nil
		}
		return "", fmt.Errorf("malformed serialized bool: %q", s)
	}

	return "", fmt.Errorf("unsupported serialized value: %q", s)
}
