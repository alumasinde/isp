// Package routeros is a dependency-free RouterOS API (TCP, port 8728) client.
//
// Only what the engine needs is implemented: connect, authenticate, run a
// command, read the resulting items and surface traps as errors.
package routeros

import (
	"bufio"
	"context"
	"crypto/md5"
	"encoding/hex"
	"errors"
	"fmt"
	"net"
	"strconv"
	"strings"
	"sync"
	"time"
)

// Item is one "!re" reply row, keyed by RouterOS property name (".id" included).
type Item map[string]string

// Get returns the value for key, or "".
func (i Item) Get(key string) string { return i[key] }

// TrapError is a RouterOS "!trap" response, i.e. the command was rejected.
type TrapError struct {
	Message  string
	Category string
	Attrs    map[string]string
}

func (e *TrapError) Error() string {
	switch {
	case e.Category != "" && e.Message != "":
		return fmt.Sprintf("routeros trap: %s (%s)", e.Message, e.Category)
	case e.Message != "":
		return "routeros trap: " + e.Message
	default:
		return "routeros trap"
	}
}

// FatalError is a RouterOS "!fatal" response; the connection is unusable.
type FatalError struct{ Message string }

func (e *FatalError) Error() string { return "routeros fatal: " + e.Message }

// Query is an API "?key=value" filter, the equivalent of a CLI [find ...].
type Query struct {
	Key      string
	Value    string
	HasValue bool
}

// Match builds a "?key=value" query.
func Match(key, value string) Query { return Query{Key: key, Value: value, HasValue: true} }

// Client is a single authenticated RouterOS API connection.
//
// It is safe for concurrent use: exchanges are serialized by a mutex, which
// also guarantees replies are read by the goroutine that sent the command.
type Client struct {
	conn    net.Conn
	br      *bufio.Reader
	mu      sync.Mutex
	timeout time.Duration
	closed  bool
}

// Dial connects and authenticates against addr ("host:port").
func Dial(ctx context.Context, addr, username, password string, timeout time.Duration) (*Client, error) {
	dialer := &net.Dialer{Timeout: timeout}
	conn, err := dialer.DialContext(ctx, "tcp", addr)
	if err != nil {
		return nil, fmt.Errorf("dial %s: %w", addr, err)
	}

	c := &Client{
		conn:    conn,
		br:      bufio.NewReaderSize(conn, 32*1024),
		timeout: timeout,
	}

	if err := c.login(username, password); err != nil {
		_ = conn.Close()
		return nil, fmt.Errorf("login %s as %q: %w", addr, username, err)
	}

	return c, nil
}

// Close closes the underlying socket.
func (c *Client) Close() error {
	c.mu.Lock()
	defer c.mu.Unlock()
	if c.closed {
		return nil
	}
	c.closed = true
	return c.conn.Close()
}

// RemoteAddr reports the socket peer address.
func (c *Client) RemoteAddr() string { return c.conn.RemoteAddr().String() }

// Run executes a command such as "/ip/hotspot/user/print".
//
// args become "=key=value" words; queries become "?key=value" filters.
func (c *Client) Run(cmd string, args map[string]string, queries ...Query) ([]Item, error) {
	if cmd == "" {
		return nil, errors.New("routeros: empty command")
	}
	if !strings.HasPrefix(cmd, "/") {
		cmd = "/" + cmd
	}

	words := make([]string, 0, 1+len(args)+len(queries))
	words = append(words, cmd)
	for _, key := range sortedKeys(args) {
		words = append(words, "="+key+"="+args[key])
	}
	for _, q := range queries {
		if q.HasValue {
			words = append(words, "?"+q.Key+"="+q.Value)
		} else {
			words = append(words, "?"+q.Key)
		}
	}

	c.mu.Lock()
	defer c.mu.Unlock()

	items, _, err := c.exchange(words)
	return items, err
}

// exchange writes one sentence and reads the reply until !done / !trap.
func (c *Client) exchange(words []string) ([]Item, map[string]string, error) {
	if c.closed {
		return nil, nil, net.ErrClosed
	}

	if err := c.conn.SetDeadline(time.Now().Add(c.timeout)); err != nil {
		return nil, nil, err
	}
	if err := writeSentence(c.conn, words); err != nil {
		return nil, nil, err
	}

	var items []Item
	for {
		reply, err := readSentence(c.br)
		if err != nil {
			return nil, nil, err
		}
		if len(reply) == 0 {
			continue
		}

		attrs := parseAttrs(reply[1:])
		switch reply[0] {
		case "!re":
			items = append(items, Item(attrs))
		case "!done":
			return items, attrs, nil
		case "!trap":
			return nil, attrs, &TrapError{
				Message:  attrs["message"],
				Category: attrs["category"],
				Attrs:    attrs,
			}
		case "!fatal":
			return nil, attrs, &FatalError{Message: strings.Join(reply[1:], " ")}
		case "!empty":
			continue
		default:
			return nil, nil, fmt.Errorf("routeros: unexpected reply %q", reply[0])
		}
	}
}

// login supports RouterOS >= 6.43 (password in the request) and the older
// challenge/response flow that replies with a "ret" challenge.
func (c *Client) login(username, password string) error {
	c.mu.Lock()
	defer c.mu.Unlock()

	_, done, err := c.exchange([]string{"/login", "=name=" + username, "=password=" + password})
	if err == nil {
		if challenge := done["ret"]; challenge != "" {
			return c.legacyLogin(username, password, challenge)
		}
		return nil
	}

	var trap *TrapError
	if !errors.As(err, &trap) {
		return err
	}

	// Legacy routers reject the password attribute; ask for a challenge.
	_, done, challengeErr := c.exchange([]string{"/login", "=name=" + username})
	if challengeErr != nil {
		return err
	}
	if challenge := done["ret"]; challenge != "" {
		return c.legacyLogin(username, password, challenge)
	}
	return err
}

func (c *Client) legacyLogin(username, password, challenge string) error {
	_, _, err := c.exchange([]string{
		"/login",
		"=name=" + username,
		"=password=" + legacyHash(challenge, password),
	})
	return err
}

// legacyHash builds "00" + md5(hexdecode(challenge) + password), the pre-6.43
// RouterOS challenge response.
func legacyHash(challenge, password string) string {
	raw, err := hex.DecodeString(challenge)
	if err != nil {
		raw = []byte(challenge)
	}
	sum := md5.Sum(append(raw, []byte(password)...))
	return "00" + hex.EncodeToString(sum[:])
}

func sortedKeys(m map[string]string) []string {
	keys := make([]string, 0, len(m))
	for k := range m {
		keys = append(keys, k)
	}
	// Insertion sort: command dictionaries are tiny, and this keeps the
	// package free of an import purely for ordering.
	for i := 1; i < len(keys); i++ {
		for j := i; j > 0 && keys[j] < keys[j-1]; j-- {
			keys[j], keys[j-1] = keys[j-1], keys[j]
		}
	}
	return keys
}

// JoinHostPort is an alias kept for convenience in callers/tests.
func JoinHostPort(host string, port int) string {
	return net.JoinHostPort(host, strconv.Itoa(port))
}
