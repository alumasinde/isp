// Package routerostest provides a fake RouterOS API endpoint for tests. It
// speaks the real wire protocol, so clients are exercised end to end.
package routerostest

import (
	"bufio"
	"net"
	"strings"
	"sync"

	"github.com/codepagol/isp/backend/internal/routeros"
)

// Call is one decoded request sentence.
type Call struct {
	Command string
	Attrs   map[string]string
	Queries map[string]string
}

// Handler replies to a command with items and/or a trap message.
type Handler func(call Call) (items []map[string]string, trap string)

// Server is a single-router fake.
type Server struct {
	ln      net.Listener
	handler Handler

	mu    sync.Mutex
	calls []Call
	conns int

	wg sync.WaitGroup
}

// Start listens on a loopback port and accepts clients until Close.
func Start(handler Handler) (*Server, error) {
	ln, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		return nil, err
	}

	s := &Server{ln: ln, handler: handler}
	s.wg.Add(1)
	go s.accept()

	return s, nil
}

// Addr is the "host:port" clients should dial.
func (s *Server) Addr() string { return s.ln.Addr().String() }

// Port is the listening TCP port.
func (s *Server) Port() int { return s.ln.Addr().(*net.TCPAddr).Port }

// Calls returns every command the server received, in order.
func (s *Server) Calls() []Call {
	s.mu.Lock()
	defer s.mu.Unlock()

	out := make([]Call, len(s.calls))
	copy(out, s.calls)
	return out
}

// Connections reports how many client sockets were accepted.
func (s *Server) Connections() int {
	s.mu.Lock()
	defer s.mu.Unlock()
	return s.conns
}

// Close stops accepting and waits for handlers to finish.
func (s *Server) Close() {
	_ = s.ln.Close()
	s.wg.Wait()
}

func (s *Server) accept() {
	defer s.wg.Done()

	for {
		conn, err := s.ln.Accept()
		if err != nil {
			return
		}

		s.mu.Lock()
		s.conns++
		s.mu.Unlock()

		s.wg.Add(1)
		go func() {
			defer s.wg.Done()
			s.handle(conn)
		}()
	}
}

func (s *Server) handle(conn net.Conn) {
	defer conn.Close()

	br := bufio.NewReader(conn)
	loggedIn := false

	for {
		words, err := routeros.ReadSentence(br)
		if err != nil {
			return
		}
		if len(words) == 0 {
			continue
		}

		call := decode(words)

		if call.Command == "/login" {
			// Modern login: accept whatever credentials arrive.
			_ = routeros.WriteSentence(conn, []string{"!done"})
			loggedIn = true
			continue
		}

		if !loggedIn {
			_ = routeros.WriteSentence(conn, []string{"!trap", "=message=not logged in"})
			continue
		}

		s.mu.Lock()
		s.calls = append(s.calls, call)
		s.mu.Unlock()

		items, trap := s.handler(call)
		if trap != "" {
			_ = routeros.WriteSentence(conn, []string{"!trap", "=message=" + trap})
			continue
		}

		for _, item := range items {
			sentence := []string{"!re"}
			for key, value := range item {
				sentence = append(sentence, "="+key+"="+value)
			}
			if err := routeros.WriteSentence(conn, sentence); err != nil {
				return
			}
		}

		if err := routeros.WriteSentence(conn, []string{"!done"}); err != nil {
			return
		}
	}
}

func decode(words []string) Call {
	call := Call{
		Command: words[0],
		Attrs:   map[string]string{},
		Queries: map[string]string{},
	}

	for _, word := range words[1:] {
		switch {
		case strings.HasPrefix(word, "="):
			key, value, _ := strings.Cut(word[1:], "=")
			call.Attrs[key] = value
		case strings.HasPrefix(word, "?"):
			key, value, _ := strings.Cut(word[1:], "=")
			call.Queries[key] = value
		}
	}

	return call
}
