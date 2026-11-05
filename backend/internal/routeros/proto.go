package routeros

import (
	"bufio"
	"fmt"
	"io"
	"strings"
)

// The RouterOS API frames a sentence as a list of words, each prefixed by its
// length. An empty word terminates the sentence. This is the exact scheme used
// by librouteros and the PHP client the application used before.

func encodeLength(n int) []byte {
	switch {
	case n < 0x80:
		return []byte{byte(n)}
	case n < 0x4000:
		return []byte{byte(n>>8) | 0x80, byte(n)}
	case n < 0x200000:
		return []byte{byte(n>>16) | 0xC0, byte(n >> 8), byte(n)}
	case n < 0x10000000:
		return []byte{byte(n>>24) | 0xE0, byte(n >> 16), byte(n >> 8), byte(n)}
	default:
		return []byte{0xF0, byte(n >> 24), byte(n >> 16), byte(n >> 8), byte(n)}
	}
}

func decodeLength(r *bufio.Reader) (int, error) {
	b, err := r.ReadByte()
	if err != nil {
		return 0, err
	}

	switch {
	case b&0x80 == 0x00:
		return int(b), nil
	case b&0xC0 == 0x80:
		b2, err := r.ReadByte()
		if err != nil {
			return 0, err
		}
		return int(b&^0x80)<<8 | int(b2), nil
	case b&0xE0 == 0xC0:
		buf := make([]byte, 2)
		if _, err := io.ReadFull(r, buf); err != nil {
			return 0, err
		}
		return int(b&^0xC0)<<16 | int(buf[0])<<8 | int(buf[1]), nil
	case b&0xF0 == 0xE0:
		buf := make([]byte, 3)
		if _, err := io.ReadFull(r, buf); err != nil {
			return 0, err
		}
		return int(b&^0xE0)<<24 | int(buf[0])<<16 | int(buf[1])<<8 | int(buf[2]), nil
	case b&0xF8 == 0xF0:
		buf := make([]byte, 4)
		if _, err := io.ReadFull(r, buf); err != nil {
			return 0, err
		}
		return int(buf[0])<<24 | int(buf[1])<<16 | int(buf[2])<<8 | int(buf[3]), nil
	default:
		return 0, fmt.Errorf("routeros: invalid word length prefix 0x%02x", b)
	}
}

func writeSentence(w io.Writer, words []string) error {
	for _, word := range words {
		if _, err := w.Write(encodeLength(len(word))); err != nil {
			return err
		}
		if _, err := io.WriteString(w, word); err != nil {
			return err
		}
	}
	_, err := w.Write([]byte{0x00})
	return err
}

// WriteSentence encodes one sentence. Exported for tooling and test servers.
func WriteSentence(w io.Writer, words []string) error { return writeSentence(w, words) }

// ReadSentence decodes one sentence. Exported for tooling and test servers.
func ReadSentence(r *bufio.Reader) ([]string, error) { return readSentence(r) }

func readSentence(r *bufio.Reader) ([]string, error) {
	var words []string
	for {
		length, err := decodeLength(r)
		if err != nil {
			return nil, err
		}
		if length == 0 {
			return words, nil
		}
		buf := make([]byte, length)
		if _, err := io.ReadFull(r, buf); err != nil {
			return nil, err
		}
		words = append(words, string(buf))
	}
}

// parseAttrs converts "=key=value" words into a map.
func parseAttrs(words []string) map[string]string {
	attrs := make(map[string]string, len(words))
	for _, word := range words {
		if !strings.HasPrefix(word, "=") {
			continue
		}
		key, value, _ := strings.Cut(word[1:], "=")
		attrs[key] = value
	}
	return attrs
}
