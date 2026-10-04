package main

import (
	"encoding/json"
	"fmt"
	"net/http"
	"strings"
	"time"
)

// Multi-router management. The panel keeps a list of routers and drives
// whichever one is flagged is_active. Each row chooses its transport via
// api_mode: "rest" (RouterOS v7 REST) or "native" (legacy binary API).

// routerInput is the JSON body accepted by the add/save/test endpoints.
type routerInput struct {
	ID       int64  `json:"id"`
	Name     string `json:"name"`
	Host     string `json:"host"`
	Port     int    `json:"port"`
	APIMode  string `json:"api_mode"`
	Username string `json:"username"`
	Password string `json:"password"`
}

// normalizeRouter fills sensible defaults so a partial payload still
// validates: rest on port 80, native on 8728, username "admin".
func normalizeRouter(row *routerRow) {
	if row.APIMode == "" {
		row.APIMode = "rest"
	}
	if row.Port == 0 {
		if row.APIMode == "native" {
			row.Port = 8728
		} else {
			row.Port = 80
		}
	}
	if row.Username == "" {
		row.Username = "admin"
	}
}

// validateRouterRow enforces the field contract shared by add/save/test.
func validateRouterRow(row *routerRow) error {
	if row.Host == "" {
		return fmt.Errorf("host is required")
	}
	if row.Port < 1 || row.Port > 65535 {
		return fmt.Errorf("port must be 1..65535")
	}
	if row.APIMode != "rest" && row.APIMode != "native" {
		return fmt.Errorf(`api_mode must be "rest" or "native"`)
	}
	return nil
}

// maskedPassword never leaks the real secret back to the browser; a
// configured password shows as "***", an empty one stays empty.
func maskedPassword(pw string) string {
	if pw == "" {
		return ""
	}
	return "***"
}

// handleRouters dispatches GET (list) and POST (add).
func (s *Store) handleRouters(w http.ResponseWriter, r *http.Request) {
	switch r.Method {
	case http.MethodGet:
		s.routersList(w, r)
	case http.MethodPost:
		s.handleRouterAdd(w, r)
	default:
		respondError(w, http.StatusMethodNotAllowed, "method not allowed")
	}
}

func (s *Store) routersList(w http.ResponseWriter, _ *http.Request) {
	rows, err := s.routersAll()
	if err != nil {
		respondError(w, http.StatusInternalServerError, err.Error())
		return
	}
	out := []map[string]any{}
	for _, row := range rows {
		out = append(out, map[string]any{
			"id":        row.ID,
			"name":      row.Name,
			"host":      row.Host,
			"port":      row.Port,
			"api_mode":  row.APIMode,
			"username":  row.Username,
			"password":  maskedPassword(row.Password),
			"is_active": row.IsActive,
		})
	}
	respondJSON(w, http.StatusOK, map[string]any{"routers": out})
}

// handleRouterAdd inserts a new router. The very first router becomes the
// active one automatically.
func (s *Store) handleRouterAdd(w http.ResponseWriter, r *http.Request) {
	var in routerInput
	if !readJSON(w, r, &in) {
		return
	}
	row := &routerRow{
		Name:     strings.TrimSpace(in.Name),
		Host:     strings.TrimSpace(in.Host),
		Port:     in.Port,
		APIMode:  strings.TrimSpace(in.APIMode),
		Username: strings.TrimSpace(in.Username),
		Password: in.Password,
	}
	normalizeRouter(row)
	if err := validateRouterRow(row); err != nil {
		respondError(w, http.StatusBadRequest, err.Error())
		return
	}
	active := 0
	var cnt int
	if err := s.App.QueryRow("SELECT COUNT(*) FROM routers").Scan(&cnt); err != nil {
		respondError(w, http.StatusInternalServerError, err.Error())
		return
	}
	if cnt == 0 {
		active = 1
	}
	res, err := s.App.Exec(`INSERT INTO routers
		(name, host, port, api_mode, username, password, is_active)
		VALUES (?, ?, ?, ?, ?, ?, ?)`,
		row.Name, row.Host, row.Port, row.APIMode, row.Username, row.Password, active)
	if err != nil {
		respondError(w, http.StatusInternalServerError, err.Error())
		return
	}
	id, _ := res.LastInsertId()
	respondJSON(w, http.StatusOK, map[string]any{"ok": true, "id": id})
}

// handleRouterSave updates an existing router. An empty password means
// "keep the stored one" so the UI can round-trip the masked value.
func (s *Store) handleRouterSave(w http.ResponseWriter, r *http.Request) {
	var in routerInput
	if !readJSON(w, r, &in) {
		return
	}
	if in.ID < 1 {
		respondError(w, http.StatusBadRequest, "id required")
		return
	}
	stored, err := s.routerByID(in.ID)
	if err != nil {
		respondError(w, http.StatusInternalServerError, err.Error())
		return
	}
	if stored == nil {
		respondError(w, http.StatusNotFound, "router not found")
		return
	}
	row := &routerRow{
		ID:       in.ID,
		Name:     strings.TrimSpace(in.Name),
		Host:     strings.TrimSpace(in.Host),
		Port:     in.Port,
		APIMode:  strings.TrimSpace(in.APIMode),
		Username: strings.TrimSpace(in.Username),
		Password: in.Password,
	}
	normalizeRouter(row)
	if err := validateRouterRow(row); err != nil {
		respondError(w, http.StatusBadRequest, err.Error())
		return
	}
	pass := row.Password
	if pass == "" {
		pass = stored.Password
	}
	if _, err := s.App.Exec(`UPDATE routers
		SET name=?, host=?, port=?, api_mode=?, username=?, password=? WHERE id=?`,
		row.Name, row.Host, row.Port, row.APIMode, row.Username, pass, in.ID); err != nil {
		respondError(w, http.StatusInternalServerError, err.Error())
		return
	}
	respondJSON(w, http.StatusOK, map[string]any{"ok": true, "id": in.ID})
}

// handleRouterDelete removes a router. If the deleted row was active and
// others remain, the first remaining router is promoted to active.
func (s *Store) handleRouterDelete(w http.ResponseWriter, r *http.Request) {
	var in struct {
		ID int64 `json:"id"`
	}
	if !readJSON(w, r, &in) {
		return
	}
	if in.ID < 1 {
		respondError(w, http.StatusBadRequest, "id required")
		return
	}
	stored, err := s.routerByID(in.ID)
	if err != nil {
		respondError(w, http.StatusInternalServerError, err.Error())
		return
	}
	if stored == nil {
		respondError(w, http.StatusNotFound, "router not found")
		return
	}
	if _, err := s.App.Exec("DELETE FROM routers WHERE id = ?", in.ID); err != nil {
		respondError(w, http.StatusInternalServerError, err.Error())
		return
	}
	if stored.IsActive == 1 {
		var nextID int64
		if err := s.App.QueryRow("SELECT id FROM routers ORDER BY id LIMIT 1").Scan(&nextID); err == nil {
			if _, err := s.App.Exec("UPDATE routers SET is_active = 1 WHERE id = ?", nextID); err != nil {
				respondError(w, http.StatusInternalServerError, err.Error())
				return
			}
		}
	}
	respondJSON(w, http.StatusOK, map[string]any{"ok": true})
}

// handleRouterActive makes one router the active target, clearing the flag
// on all others first.
func (s *Store) handleRouterActive(w http.ResponseWriter, r *http.Request) {
	var in struct {
		ID int64 `json:"id"`
	}
	if !readJSON(w, r, &in) {
		return
	}
	if in.ID < 1 {
		respondError(w, http.StatusBadRequest, "id required")
		return
	}
	row, err := s.routerByID(in.ID)
	if err != nil {
		respondError(w, http.StatusInternalServerError, err.Error())
		return
	}
	if row == nil {
		respondError(w, http.StatusNotFound, "router not found")
		return
	}
	tx, err := s.App.Begin()
	if err != nil {
		respondError(w, http.StatusInternalServerError, err.Error())
		return
	}
	defer tx.Rollback()
	if _, err := tx.Exec("UPDATE routers SET is_active = 0"); err != nil {
		respondError(w, http.StatusInternalServerError, err.Error())
		return
	}
	if _, err := tx.Exec("UPDATE routers SET is_active = 1 WHERE id = ?", in.ID); err != nil {
		respondError(w, http.StatusInternalServerError, err.Error())
		return
	}
	if err := tx.Commit(); err != nil {
		respondError(w, http.StatusInternalServerError, err.Error())
		return
	}
	respondJSON(w, http.StatusOK, map[string]any{"ok": true})
}

// handleRouterTest probes a router without persisting anything. It accepts
// either a full inline definition or {"id":N} to reuse a stored row (with a
// password override when a non-empty password is supplied).
func (s *Store) handleRouterTest(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodPost {
		respondError(w, http.StatusMethodNotAllowed, "method not allowed")
		return
	}
	var in routerInput
	if !readJSON(w, r, &in) {
		return
	}
	var row *routerRow
	if in.ID > 0 {
		stored, err := s.routerByID(in.ID)
		if err != nil {
			respondError(w, http.StatusInternalServerError, err.Error())
			return
		}
		if stored == nil {
			respondError(w, http.StatusNotFound, "router not found")
			return
		}
		row = stored
		if in.Password != "" {
			row.Password = in.Password
		}
	} else {
		row = &routerRow{
			Host:     strings.TrimSpace(in.Host),
			Port:     in.Port,
			APIMode:  strings.TrimSpace(in.APIMode),
			Username: strings.TrimSpace(in.Username),
			Password: in.Password,
		}
		normalizeRouter(row)
		if err := validateRouterRow(row); err != nil {
			respondError(w, http.StatusBadRequest, err.Error())
			return
		}
	}

	identity, err := testRouterConn(row)
	if err != nil {
		respondJSON(w, http.StatusOK, map[string]any{"ok": false, "error": err.Error()})
		return
	}
	respondJSON(w, http.StatusOK, map[string]any{"ok": true, "identity": identity})
}

// testRouterConn connects and reads the router identity over the row's
// transport. A 10s budget keeps a dead host from hanging the request.
func testRouterConn(row *routerRow) (string, error) {
	if row.APIMode == "native" {
		nc := &NativeClient{host: row.Host, port: row.Port, user: row.Username, pass: row.Password}
		defer nc.Close()
		rows, err := nc.cmd("/system/identity/print")
		if err != nil {
			return "", err
		}
		if len(rows) > 0 {
			return rows[0]["name"], nil
		}
		return "", nil
	}
	api := routerFromRow(row)
	rc, ok := api.(*RouterClient)
	if !ok {
		return "", fmt.Errorf("unsupported api_mode %q", row.APIMode)
	}
	rc.hc.Timeout = 10 * time.Second
	return restIdentity(rc)
}

// restIdentity reads /rest/system/identity, tolerating both the array form
// RouterOS usually returns and a bare object.
func restIdentity(rc *RouterClient) (string, error) {
	var raw json.RawMessage
	if err := rc.json(http.MethodGet, "/rest/system/identity", nil, &raw); err != nil {
		return "", err
	}
	var arr []map[string]any
	if err := json.Unmarshal(raw, &arr); err == nil {
		if len(arr) > 0 {
			return mtStr(arr[0], "name"), nil
		}
		return "", nil
	}
	var obj map[string]any
	if err := json.Unmarshal(raw, &obj); err == nil {
		return mtStr(obj, "name"), nil
	}
	return "", fmt.Errorf("unexpected identity response")
}
