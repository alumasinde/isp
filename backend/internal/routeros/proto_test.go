package routeros

import (
	"bufio"
	"bytes"
	"reflect"
	"testing"
)

func TestEncodeLengthMatchesProtocol(t *testing.T) {
	cases := []struct {
		n    int
		want []byte
	}{
		{0x00, []byte{0x00}},
		{0x7F, []byte{0x7F}},
		{0x80, []byte{0x80, 0x80}},
		{0x3FFF, []byte{0xBF, 0xFF}},
		{0x4000, []byte{0xC0, 0x40, 0x00}},
		{0x1FFFFF, []byte{0xDF, 0xFF, 0xFF}},
		{0x200000, []byte{0xE0, 0x20, 0x00, 0x00}},
		{0x0FFFFFFF, []byte{0xEF, 0xFF, 0xFF, 0xFF}},
		{0x10000000, []byte{0xF0, 0x10, 0x00, 0x00, 0x00}},
	}

	for _, tc := range cases {
		got := encodeLength(tc.n)
		if !bytes.Equal(got, tc.want) {
			t.Fatalf("encodeLength(%#x) = % x, want % x", tc.n, got, tc.want)
		}

		decoded, err := decodeLength(bufio.NewReader(bytes.NewReader(got)))
		if err != nil {
			t.Fatalf("decodeLength(%#x): %v", tc.n, err)
		}
		if decoded != tc.n {
			t.Fatalf("decodeLength round trip: got %d, want %d", decoded, tc.n)
		}
	}
}

func TestSentenceRoundTrip(t *testing.T) {
	words := []string{"/ip/hotspot/user/print", "=name=voucher-1", "?name=voucher-1"}

	var buf bytes.Buffer
	if err := WriteSentence(&buf, words); err != nil {
		t.Fatalf("WriteSentence: %v", err)
	}

	got, err := ReadSentence(bufio.NewReader(&buf))
	if err != nil {
		t.Fatalf("ReadSentence: %v", err)
	}
	if !reflect.DeepEqual(got, words) {
		t.Fatalf("got %#v, want %#v", got, words)
	}
}

func TestReadSentenceRejectsBadPrefix(t *testing.T) {
	// 0xF8..0xFF are not valid length prefixes.
	if _, err := decodeLength(bufio.NewReader(bytes.NewReader([]byte{0xFF}))); err == nil {
		t.Fatal("expected error for invalid length prefix")
	}
}
