package main

import (
	"crypto/rand"
	"crypto/sha256"
	"encoding/hex"
	"sync"
	"time"
)

const adminCookieName = "aircoins_admin"
const sessionDays = 7

// ---- admin sessions (server-side tokens, cookie carries a random value) --

func (s *Store) sessionCreate(username string) (string, error) {
	raw := make([]byte, 32)
	if _, err := rand.Read(raw); err != nil {
		return "", err
	}
	token := hex.EncodeToString(raw)
	sum := sha256.Sum256([]byte(token))
	_, err := s.App.Exec(`INSERT INTO admin_sessions (token_hash, username, expires_at)
		VALUES (?, ?, datetime('now', '+`+itoa(sessionDays)+` days'))`,
		hex.EncodeToString(sum[:]), username)
	if err != nil {
		return "", err
	}
	return token, nil
}

func (s *Store) sessionValid(token string) (string, bool) {
	if token == "" {
		return "", false
	}
	sum := sha256.Sum256([]byte(token))
	var username string
	err := s.App.QueryRow(`SELECT username FROM admin_sessions
		WHERE token_hash = ? AND expires_at > datetime('now')`,
		hex.EncodeToString(sum[:])).Scan(&username)
	if err != nil {
		return "", false
	}
	return username, true
}

func (s *Store) sessionDelete(token string) error {
	sum := sha256.Sum256([]byte(token))
	_, err := s.App.Exec("DELETE FROM admin_sessions WHERE token_hash = ?",
		hex.EncodeToString(sum[:]))
	return err
}

func (s *Store) sessionGC() error {
	_, err := s.App.Exec("DELETE FROM admin_sessions WHERE expires_at <= datetime('now')")
	return err
}

func itoa(n int) string {
	if n == 0 {
		return "0"
	}
	neg := n < 0
	if neg {
		n = -n
	}
	var buf [20]byte
	i := len(buf)
	for n > 0 {
		i--
		buf[i] = byte('0' + n%10)
		n /= 10
	}
	if neg {
		i--
		buf[i] = '-'
	}
	return string(buf[i:])
}

// ---- login throttling (per source IP, in memory) -------------------------

type attempt struct {
	count int
	until time.Time
	seen  time.Time
}

type loginThrottle struct {
	mu    sync.Mutex
	fails map[string]*attempt
}

const (
	throttleMaxFails   = 5
	throttleLockMins   = 5
	throttleForgetMins = 15
)

func newLoginThrottle() *loginThrottle {
	return &loginThrottle{fails: map[string]*attempt{}}
}

func (t *loginThrottle) blocked(key string) bool {
	t.mu.Lock()
	defer t.mu.Unlock()
	a, ok := t.fails[key]
	if !ok {
		return false
	}
	now := time.Now()
	if !a.until.IsZero() {
		if now.Before(a.until) {
			return true
		}
		// Lock expired — clean the entry.
		delete(t.fails, key)
		return false
	}
	if a.count > 0 && now.Sub(a.seen) > throttleForgetMins*time.Minute {
		delete(t.fails, key)
	}
	return false
}

func (t *loginThrottle) fail(key string) {
	t.mu.Lock()
	defer t.mu.Unlock()
	a := t.fails[key]
	if a == nil {
		a = &attempt{}
		t.fails[key] = a
	}
	a.count++
	a.seen = time.Now()
	if a.count >= throttleMaxFails {
		a.until = time.Now().Add(throttleLockMins * time.Minute)
		a.count = 0
	}
}

func (t *loginThrottle) reset(key string) {
	t.mu.Lock()
	defer t.mu.Unlock()
	delete(t.fails, key)
}
