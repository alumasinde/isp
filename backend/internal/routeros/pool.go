package routeros

import (
	"context"
	"errors"
	"fmt"
	"io"
	"net"
	"sync"
	"time"
)

// Target describes one managed router.
type Target struct {
	Name     string
	Host     string
	Port     int
	Username string
	Password string
	Timeout  time.Duration
}

// Addr returns the "host:port" API endpoint.
func (t Target) Addr() string { return JoinHostPort(t.Host, t.Port) }

// Dialer opens an authenticated client; replaceable in tests.
type Dialer func(ctx context.Context, t Target) (*Client, error)

// DefaultDialer dials the RouterOS API with the target's timeout.
func DefaultDialer(ctx context.Context, t Target) (*Client, error) {
	timeout := t.Timeout
	if timeout <= 0 {
		timeout = 5 * time.Second
	}
	return Dial(ctx, t.Addr(), t.Username, t.Password, timeout)
}

type pooled struct {
	client   *Client
	lastUsed time.Time
}

// Pool keeps one authenticated connection per router so the engine does not
// pay a TCP + login round trip on every sweep.
type Pool struct {
	mu      sync.Mutex
	conns   map[string]*pooled
	dial    Dialer
	maxIdle time.Duration
	now     func() time.Time
}

// NewPool builds a pool. Connections idle for longer than maxIdle are
// re-established, which avoids using a socket the router already dropped.
func NewPool(dial Dialer, maxIdle time.Duration) *Pool {
	if dial == nil {
		dial = DefaultDialer
	}
	if maxIdle <= 0 {
		maxIdle = 2 * time.Minute
	}
	return &Pool{
		conns:   make(map[string]*pooled),
		dial:    dial,
		maxIdle: maxIdle,
		now:     time.Now,
	}
}

// Do runs fn with a connection for t, reconnecting once if the socket failed.
func (p *Pool) Do(ctx context.Context, t Target, fn func(*Client) error) error {
	client, err := p.get(ctx, t)
	if err != nil {
		return err
	}

	err = fn(client)
	if err == nil {
		p.touch(t.Name)
		return nil
	}
	if !isConnError(err) {
		return err
	}

	// Stale socket: drop it, dial again and retry the command once. The
	// commands the engine issues (set/remove/print) are idempotent.
	p.drop(t.Name, client)

	client, retryErr := p.get(ctx, t)
	if retryErr != nil {
		return fmt.Errorf("%w (reconnect failed: %v)", err, retryErr)
	}
	if err := fn(client); err != nil {
		if isConnError(err) {
			p.drop(t.Name, client)
		} else {
			p.touch(t.Name)
		}
		return err
	}
	p.touch(t.Name)
	return nil
}

// Drop closes and forgets the cached connection for a router.
func (p *Pool) Drop(name string) {
	p.mu.Lock()
	conn, ok := p.conns[name]
	delete(p.conns, name)
	p.mu.Unlock()

	if ok {
		_ = conn.client.Close()
	}
}

// Close closes every cached connection.
func (p *Pool) Close() error {
	p.mu.Lock()
	conns := p.conns
	p.conns = make(map[string]*pooled)
	p.mu.Unlock()

	for _, conn := range conns {
		_ = conn.client.Close()
	}
	return nil
}

func (p *Pool) get(ctx context.Context, t Target) (*Client, error) {
	now := p.now()

	p.mu.Lock()
	conn, ok := p.conns[t.Name]
	p.mu.Unlock()

	if ok {
		if now.Sub(conn.lastUsed) <= p.maxIdle {
			return conn.client, nil
		}
		p.drop(t.Name, conn.client)
	}

	client, err := p.dial(ctx, t)
	if err != nil {
		return nil, err
	}

	p.mu.Lock()
	// A concurrent caller may have connected first; keep theirs and close ours.
	if existing, ok := p.conns[t.Name]; ok && existing.client != client {
		p.mu.Unlock()
		_ = client.Close()
		return existing.client, nil
	}
	p.conns[t.Name] = &pooled{client: client, lastUsed: now}
	p.mu.Unlock()

	return client, nil
}

func (p *Pool) drop(name string, client *Client) {
	p.mu.Lock()
	if conn, ok := p.conns[name]; ok && conn.client == client {
		delete(p.conns, name)
	}
	p.mu.Unlock()
	_ = client.Close()
}

func (p *Pool) touch(name string) {
	now := p.now()
	p.mu.Lock()
	if conn, ok := p.conns[name]; ok {
		conn.lastUsed = now
	}
	p.mu.Unlock()
}

// isConnError reports transport failures worth a reconnect. Authenticated
// rejections (traps) and fatal router errors are not retried.
func isConnError(err error) bool {
	if err == nil {
		return false
	}

	var trap *TrapError
	if errors.As(err, &trap) {
		return false
	}
	var fatal *FatalError
	if errors.As(err, &fatal) {
		return false
	}

	if errors.Is(err, io.EOF) || errors.Is(err, io.ErrUnexpectedEOF) || errors.Is(err, net.ErrClosed) {
		return true
	}

	var netErr net.Error
	if errors.As(err, &netErr) {
		return true
	}

	return false
}
