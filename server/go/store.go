package main

import (
	"database/sql"
	"fmt"
	"os"
	"path/filepath"

	"golang.org/x/crypto/bcrypt"
	_ "modernc.org/sqlite" // registers the pure-Go "sqlite" driver (CGO-free)
)

// Store owns the two SQLite databases:
//
//	App    — application state (admin accounts, vouchers, vendo, logs)
//	Radius — the RADIUS DB also served by FreeRADIUS (radcheck/radreply/...)
type Store struct {
	App      *sql.DB
	Radius   *sql.DB
	dataDir  string
	throttle *loginThrottle
}

func openStore(dir string) (*Store, error) {
	if err := os.MkdirAll(dir, 0o775); err != nil {
		return nil, err
	}
	app, err := openSQLite(filepath.Join(dir, "aircoins.db"))
	if err != nil {
		return nil, fmt.Errorf("open app db: %w", err)
	}
	rad, err := openSQLite(filepath.Join(dir, "radius.db"))
	if err != nil {
		app.Close()
		return nil, fmt.Errorf("open radius db: %w", err)
	}
	s := &Store{App: app, Radius: rad, dataDir: dir, throttle: newLoginThrottle()}
	if err := s.migrateApp(); err != nil {
		return nil, fmt.Errorf("migrate app db: %w", err)
	}
	if err := s.migrateRadius(); err != nil {
		return nil, fmt.Errorf("migrate radius db: %w", err)
	}
	return s, nil
}

func openSQLite(path string) (*sql.DB, error) {
	dsn := "file:" + path +
		"?_pragma=busy_timeout(5000)" +
		"&_pragma=journal_mode(WAL)" +
		"&_pragma=foreign_keys(ON)"
	db, err := sql.Open("sqlite", dsn)
	if err != nil {
		return nil, err
	}
	// One writer connection serialises our own access; FreeRADIUS has its
	// own connection and busy_timeout bridges the rest.
	db.SetMaxOpenConns(1)
	if err := db.Ping(); err != nil {
		db.Close()
		return nil, err
	}
	return db, nil
}

// ---------------------------------------------------------------- app DB

func (s *Store) migrateApp() error {
	stmts := []string{
		`CREATE TABLE IF NOT EXISTS admins (
			id            INTEGER PRIMARY KEY AUTOINCREMENT,
			username      TEXT NOT NULL UNIQUE,
			password_hash TEXT NOT NULL,
			created_at    TEXT NOT NULL DEFAULT (datetime('now'))
		)`,
		`CREATE TABLE IF NOT EXISTS admin_sessions (
			token_hash TEXT PRIMARY KEY,
			username   TEXT NOT NULL,
			created_at TEXT NOT NULL DEFAULT (datetime('now')),
			expires_at TEXT NOT NULL
		)`,
		`CREATE TABLE IF NOT EXISTS settings (
			key   TEXT PRIMARY KEY,
			value TEXT NOT NULL DEFAULT ''
		)`,
		`CREATE TABLE IF NOT EXISTS vendo_devices (
			id                INTEGER PRIMARY KEY AUTOINCREMENT,
			name              TEXT NOT NULL,
			location          TEXT NOT NULL DEFAULT '',
			api_url           TEXT NOT NULL DEFAULT '',
			minutes_per_pulse INTEGER NOT NULL DEFAULT 15,
			enabled           INTEGER NOT NULL DEFAULT 1,
			last_seen         TEXT
		)`,
		`CREATE TABLE IF NOT EXISTS vendo_rates (
			id       INTEGER PRIMARY KEY AUTOINCREMENT,
			vendo_id INTEGER NOT NULL REFERENCES vendo_devices(id) ON DELETE CASCADE,
			coins    INTEGER NOT NULL,
			minutes  INTEGER NOT NULL
		)`,
		`CREATE TABLE IF NOT EXISTS vouchers (
			id         INTEGER PRIMARY KEY AUTOINCREMENT,
			code       TEXT NOT NULL UNIQUE,
			minutes    INTEGER NOT NULL,
			batch      TEXT NOT NULL DEFAULT '',
			created_at TEXT NOT NULL DEFAULT (datetime('now'))
		)`,
		`CREATE TABLE IF NOT EXISTS coin_log (
			id         INTEGER PRIMARY KEY AUTOINCREMENT,
			mac        TEXT NOT NULL,
			coins      INTEGER NOT NULL,
			minutes    INTEGER NOT NULL,
			created_at TEXT NOT NULL DEFAULT (datetime('now'))
		)`,
		`CREATE INDEX IF NOT EXISTS idx_coin_log_created ON coin_log (created_at)`,
		`CREATE TABLE IF NOT EXISTS event_log (
			id         INTEGER PRIMARY KEY AUTOINCREMENT,
			level      TEXT NOT NULL DEFAULT 'info',
			source     TEXT NOT NULL,
			message    TEXT NOT NULL,
			created_at TEXT NOT NULL DEFAULT (datetime('now'))
		)`,
	}
	for _, q := range stmts {
		if _, err := s.App.Exec(q); err != nil {
			return fmt.Errorf("%q: %w", q[:40], err)
		}
	}

	// Default vendo device + promo tiers on first boot only.
	var n int
	if err := s.App.QueryRow("SELECT COUNT(*) FROM vendo_devices").Scan(&n); err != nil {
		return err
	}
	if n == 0 {
		if _, err := s.App.Exec(`INSERT INTO vendo_devices (name, location, api_url, minutes_per_pulse, enabled)
			VALUES ('Vendo 1', 'Counter', '', 15, 1)`); err != nil {
			return err
		}
		if _, err := s.App.Exec(`INSERT INTO vendo_rates (vendo_id, coins, minutes)
			SELECT v.id, t.c, t.c * v.minutes_per_pulse FROM vendo_devices v
			CROSS JOIN (SELECT 1 AS c UNION SELECT 3 UNION SELECT 5 UNION SELECT 10) t`); err != nil {
			return err
		}
	}
	return nil
}

// ------------------------------------------------------------- radius DB

func (s *Store) migrateRadius() error {
	stmts := []string{
		`CREATE TABLE IF NOT EXISTS radacct (
			radacctid INTEGER PRIMARY KEY AUTOINCREMENT,
			acctsessionid VARCHAR(64) NOT NULL,
			acctuniqueid VARCHAR(32) NOT NULL UNIQUE,
			username VARCHAR(64),
			realm VARCHAR(64),
			nasipaddress VARCHAR(15),
			nasportid VARCHAR(15),
			nasporttype VARCHAR(32),
			acctstarttime DATETIME,
			acctupdatetime DATETIME,
			acctstoptime DATETIME,
			acctsessiontime INTEGER,
			acctauthentic VARCHAR(32),
			connectinfo_start VARCHAR(128),
			connectinfo_stop VARCHAR(128),
			acctinputoctets BIGINT,
			acctoutputoctets BIGINT,
			calledstationid VARCHAR(50),
			callingstationid VARCHAR(50),
			servicetype VARCHAR(32),
			framedprotocol VARCHAR(32),
			framedipaddress VARCHAR(15),
			acctterminatecause VARCHAR(32)
		)`,
		`CREATE INDEX IF NOT EXISTS idx_radacct_user ON radacct (username)`,
		`CREATE INDEX IF NOT EXISTS idx_radacct_session ON radacct (acctsessionid, username, nasipaddress)`,
		`CREATE TABLE IF NOT EXISTS radcheck (
			id INTEGER PRIMARY KEY AUTOINCREMENT,
			username VARCHAR(64) NOT NULL,
			attribute VARCHAR(64) NOT NULL,
			op CHAR(2) NOT NULL DEFAULT ':=',
			value VARCHAR(253)
		)`,
		`CREATE INDEX IF NOT EXISTS idx_radcheck_user ON radcheck (username)`,
		`CREATE TABLE IF NOT EXISTS radreply (
			id INTEGER PRIMARY KEY AUTOINCREMENT,
			username VARCHAR(64) NOT NULL,
			attribute VARCHAR(64) NOT NULL,
			op CHAR(2) NOT NULL DEFAULT ':=',
			value VARCHAR(253)
		)`,
		`CREATE INDEX IF NOT EXISTS idx_radreply_user ON radreply (username)`,
		`CREATE TABLE IF NOT EXISTS radgroupcheck (
			id INTEGER PRIMARY KEY AUTOINCREMENT,
			groupname VARCHAR(64) NOT NULL,
			attribute VARCHAR(64) NOT NULL,
			op CHAR(2) NOT NULL DEFAULT ':=',
			value VARCHAR(253)
		)`,
		`CREATE TABLE IF NOT EXISTS radgroupreply (
			id INTEGER PRIMARY KEY AUTOINCREMENT,
			groupname VARCHAR(64) NOT NULL,
			attribute VARCHAR(64) NOT NULL,
			op CHAR(2) NOT NULL DEFAULT ':=',
			value VARCHAR(253)
		)`,
		`CREATE TABLE IF NOT EXISTS radusergroup (
			username VARCHAR(64) NOT NULL,
			groupname VARCHAR(64) NOT NULL,
			priority INTEGER NOT NULL DEFAULT 0,
			PRIMARY KEY (username, groupname)
		)`,
		`CREATE TABLE IF NOT EXISTS radpostauth (
			id INTEGER PRIMARY KEY AUTOINCREMENT,
			username VARCHAR(64) NOT NULL,
			pass VARCHAR(64),
			reply VARCHAR(32),
			authdate DATETIME
		)`,
		`INSERT OR IGNORE INTO radgroupreply (groupname, attribute, op, value) VALUES
			('hotspot', 'Mikrotik-Rate-Limit', ':=', '2M/2M'),
			('hotspot', 'Idle-Timeout', ':=', '600'),
			('hotspot', 'Acct-Interim-Interval', ':=', '300')`,
	}
	for _, q := range stmts {
		if _, err := s.Radius.Exec(q); err != nil {
			return fmt.Errorf("%q: %w", q[:40], err)
		}
	}
	return nil
}

// -------------------------------------------------------------- settings

func (s *Store) getSetting(key, def string) string {
	var v string
	err := s.App.QueryRow("SELECT value FROM settings WHERE key = ?", key).Scan(&v)
	if err != nil || v == "" {
		return def
	}
	return v
}

func (s *Store) setSetting(key, val string) error {
	_, err := s.App.Exec(`INSERT INTO settings (key, value) VALUES (?, ?)
		ON CONFLICT(key) DO UPDATE SET value = excluded.value`, key, val)
	return err
}

func (s *Store) settingsAll() map[string]string {
	m := map[string]string{}
	rows, err := s.App.Query("SELECT key, value FROM settings")
	if err != nil {
		return m
	}
	defer rows.Close()
	for rows.Next() {
		var k, v string
		if rows.Scan(&k, &v) == nil {
			m[k] = v
		}
	}
	return m
}

// ---------------------------------------------------------------- admins

func (s *Store) adminUpsert(username, password string) error {
	hash, err := bcrypt.GenerateFromPassword([]byte(password), bcrypt.DefaultCost)
	if err != nil {
		return err
	}
	_, err = s.App.Exec(`INSERT INTO admins (username, password_hash) VALUES (?, ?)
		ON CONFLICT(username) DO UPDATE SET password_hash = excluded.password_hash`,
		username, string(hash))
	return err
}

func (s *Store) adminVerify(username, password string) (bool, error) {
	var hash string
	err := s.App.QueryRow("SELECT password_hash FROM admins WHERE username = ?", username).Scan(&hash)
	if err == sql.ErrNoRows {
		// Burn comparable time so missing users are not distinguishable.
		bcrypt.CompareHashAndPassword([]byte("$2a$10$7EqJtq98hPqEX7fNZaFWoOhi5B0X0wJk9yGKQGmZ0hO9eZJ8pJk6a"), []byte(password))
		return false, nil
	}
	if err != nil {
		return false, err
	}
	return bcrypt.CompareHashAndPassword([]byte(hash), []byte(password)) == nil, nil
}
