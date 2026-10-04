package main

import (
	"crypto/rand"
	"database/sql"
	"errors"
	"fmt"
	"math/big"
	"regexp"
	"strings"
)

var macPattern = regexp.MustCompile(`^[0-9A-F]{12}$`)

// normalizeMAC strips separators and uppercases; returns "" when invalid.
func normalizeMAC(raw string) string {
	clean := strings.Map(func(r rune) rune {
		switch r {
		case ':', '-', '.', ' ':
			return -1
		}
		return r
	}, strings.ToUpper(strings.TrimSpace(raw)))
	if !macPattern.MatchString(clean) {
		return ""
	}
	return clean
}

// randCode returns a human-safe random code (no 0/O/1/I).
func randCode(n int) string {
	const alphabet = "ABCDEFGHJKLMNPQRSTUVWXYZ23456789"
	out := make([]byte, n)
	for i := range out {
		idx, err := rand.Int(rand.Reader, big.NewInt(int64(len(alphabet))))
		if err != nil {
			// crypto/rand failure is unrecoverable in practice
			panic(err)
		}
		out[i] = alphabet[idx.Int64()]
	}
	return string(out)
}

func (s *Store) radiusUserExists(username string) (bool, error) {
	var one int
	err := s.Radius.QueryRow(`SELECT 1 FROM radcheck WHERE username = ?
		UNION SELECT 1 FROM radreply WHERE username = ? LIMIT 1`,
		username, username).Scan(&one)
	if errors.Is(err, sql.ErrNoRows) {
		return false, nil
	}
	return err == nil, err
}

// radiusRemoveUser drops check/reply/group rows; radacct history is kept.
func (s *Store) radiusRemoveUser(username string) error {
	for _, q := range []string{
		`DELETE FROM radcheck WHERE username = ?`,
		`DELETE FROM radreply WHERE username = ?`,
		`DELETE FROM radusergroup WHERE username = ?`,
	} {
		if _, err := s.Radius.Exec(q, username); err != nil {
			return err
		}
	}
	return nil
}

// radiusAddUser (re)creates a RADIUS user. An empty password means the
// username is its own password (voucher codes, MAC addresses).
func (s *Store) radiusAddUser(username, password string, seconds int, group string) error {
	if password == "" {
		password = username
	}
	if err := s.radiusRemoveUser(username); err != nil {
		return err
	}
	tx, err := s.Radius.Begin()
	if err != nil {
		return err
	}
	defer tx.Rollback()
	if _, err := tx.Exec(`INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Cleartext-Password', ':=', ?)`,
		username, password); err != nil {
		return err
	}
	if _, err := tx.Exec(`INSERT INTO radreply (username, attribute, op, value) VALUES (?, 'Session-Timeout', ':=', ?)`,
		username, fmt.Sprintf("%d", seconds)); err != nil {
		return err
	}
	if _, err := tx.Exec(`INSERT OR IGNORE INTO radusergroup (username, groupname) VALUES (?, ?)`,
		username, group); err != nil {
		return err
	}
	return tx.Commit()
}

func (s *Store) radiusGetSessionSeconds(username string) (int, error) {
	var v int
	err := s.Radius.QueryRow(`SELECT COALESCE(
		(SELECT CAST(value AS INTEGER) FROM radreply
		 WHERE username = ? AND attribute = 'Session-Timeout' LIMIT 1), 0)`,
		username).Scan(&v)
	return v, err
}

// radiusExtendUser adds seconds to a user's Session-Timeout budget,
// creating the user (password = username) when missing. Returns total.
// The router enforces the budget at login time; an already-online client
// picks the new value up on its next MAC login (portal auto re-login).
func (s *Store) radiusExtendUser(username string, add int, group string) (int, error) {
	cur, err := s.radiusGetSessionSeconds(username)
	if err != nil {
		return 0, err
	}
	total := cur + add
	exists, err := s.radiusUserExists(username)
	if err != nil {
		return 0, err
	}
	if !exists {
		return total, s.radiusAddUser(username, username, total, group)
	}
	if _, err := s.Radius.Exec(`DELETE FROM radreply WHERE username = ? AND attribute = 'Session-Timeout'`,
		username); err != nil {
		return 0, err
	}
	_, err = s.Radius.Exec(`INSERT INTO radreply (username, attribute, op, value) VALUES (?, 'Session-Timeout', ':=', ?)`,
		username, fmt.Sprintf("%d", total))
	return total, err
}

// radiusListUsers returns JSON-ready user rows (password included; the
// credentials are codes this panel issued in the first place).
func (s *Store) radiusListUsers(filter string) ([]map[string]any, error) {
	if filter == "" {
		filter = "%"
	}
	rows, err := s.Radius.Query(`
		SELECT u.username,
			(SELECT c.value FROM radcheck c WHERE c.username = u.username
			 AND c.attribute = 'Cleartext-Password' LIMIT 1) AS password,
			COALESCE((SELECT CAST(r.value AS INTEGER) FROM radreply r WHERE r.username = u.username
			 AND r.attribute = 'Session-Timeout' LIMIT 1), 0) AS seconds,
			(SELECT g.groupname FROM radusergroup g WHERE g.username = u.username LIMIT 1) AS grp,
			(SELECT COUNT(*) FROM radacct a WHERE a.username = u.username) AS sessions,
			(SELECT MIN(a.acctstarttime) FROM radacct a WHERE a.username = u.username) AS first_use
		FROM (SELECT DISTINCT username FROM radcheck
			UNION SELECT DISTINCT username FROM radreply) u
		WHERE u.username LIKE ?
		ORDER BY u.username LIMIT 1000`, filter)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	out := []map[string]any{}
	for rows.Next() {
		var username string
		var password, grp, firstUse sql.NullString
		var seconds, sessions int
		if err := rows.Scan(&username, &password, &seconds, &grp, &sessions, &firstUse); err != nil {
			return nil, err
		}
		row := map[string]any{
			"username":  username,
			"password":  password.String,
			"seconds":   seconds,
			"group":     grp.String,
			"sessions":  sessions,
			"first_use": nilIfEmpty(firstUse.String),
		}
		if macPattern.MatchString(username) {
			row["kind"] = "mac"
		} else {
			row["kind"] = "user"
		}
		out = append(out, row)
	}
	return out, rows.Err()
}

// radiusUsedMap looks up radacct usage for a list of usernames
// (voucher "used" state is derived from accounting, not a status flag).
func (s *Store) radiusUsedMap(codes []string) (map[string]map[string]any, error) {
	out := map[string]map[string]any{}
	const chunk = 200
	for start := 0; start < len(codes); start += chunk {
		end := start + chunk
		if end > len(codes) {
			end = len(codes)
		}
		part := codes[start:end]
		ph := strings.TrimRight(strings.Repeat("?,", len(part)), ",")
		args := make([]any, len(part))
		for i, c := range part {
			args[i] = c
		}
		rows, err := s.Radius.Query(`SELECT username, MIN(acctstarttime), COUNT(*)
			FROM radacct WHERE username IN (`+ph+`) GROUP BY username`, args...)
		if err != nil {
			return nil, err
		}
		for rows.Next() {
			var username string
			var first sql.NullString
			var n int
			if err := rows.Scan(&username, &first, &n); err == nil {
				out[username] = map[string]any{"used": true, "first_use": nilIfEmpty(first.String), "sessions": n}
			}
		}
		rows.Close()
		if err := rows.Err(); err != nil {
			return nil, err
		}
	}
	return out, nil
}

// radiusSetGroupReply upserts reply attributes for a user group.
func (s *Store) radiusSetGroupReply(group string, attrs map[string]string) error {
	tx, err := s.Radius.Begin()
	if err != nil {
		return err
	}
	defer tx.Rollback()
	for attr, val := range attrs {
		if _, err := tx.Exec(`DELETE FROM radgroupreply WHERE groupname = ? AND attribute = ?`,
			group, attr); err != nil {
			return err
		}
		if val == "" {
			continue
		}
		if _, err := tx.Exec(`INSERT INTO radgroupreply (groupname, attribute, op, value) VALUES (?, ?, ':=', ?)`,
			group, attr, val); err != nil {
			return err
		}
	}
	return tx.Commit()
}

// radiusGroupReplies returns the hotspot group's reply attributes.
func (s *Store) radiusGroupReplies(group string) (map[string]string, error) {
	rows, err := s.Radius.Query(`SELECT attribute, value FROM radgroupreply WHERE groupname = ?`, group)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	m := map[string]string{}
	for rows.Next() {
		var a, v string
		if rows.Scan(&a, &v) == nil {
			m[a] = v
		}
	}
	return m, rows.Err()
}

// voucherCreate writes the RADIUS credential + the app inventory row.
func (s *Store) voucherCreate(code string, minutes int, batch string) error {
	if err := s.radiusAddUser(code, code, minutes*60, "hotspot"); err != nil {
		return err
	}
	_, err := s.App.Exec(`INSERT INTO vouchers (code, minutes, batch) VALUES (?, ?, ?)`,
		code, minutes, batch)
	return err
}

func nilIfEmpty(sv string) any {
	if sv == "" {
		return nil
	}
	return sv
}
