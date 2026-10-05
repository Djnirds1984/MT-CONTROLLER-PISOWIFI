package main

import (
	"fmt"
	"net/http"
	"net/url"
	"regexp"
	"strings"
)

// ------------------------------------------------------------ vendo CRUD

func (s *Store) handleVendoAdmin(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodGet {
		respondError(w, http.StatusMethodNotAllowed, "method not allowed")
		return
	}
	type devRow struct {
		id      int
		name    string
		loc     string
		apiURL  string
		mpp     int
		enabled int
	}
	var found []devRow
	rows, err := s.App.Query(`SELECT id, name, location, api_url, minutes_per_pulse, enabled
		FROM vendo_devices ORDER BY id`)
	if err != nil {
		respondError(w, http.StatusInternalServerError, err.Error())
		return
	}
	for rows.Next() {
		var d devRow
		if rows.Scan(&d.id, &d.name, &d.loc, &d.apiURL, &d.mpp, &d.enabled) == nil {
			found = append(found, d)
		}
	}
	rows.Close()

	type rate struct {
		Coins   int `json:"coins"`
		Minutes int `json:"minutes"`
	}
	devs := []map[string]any{}
	for _, d := range found {
		rates := []rate{}
		rrows, rerr := s.App.Query(`SELECT coins, minutes FROM vendo_rates
			WHERE vendo_id = ? ORDER BY coins`, d.id)
		if rerr == nil {
			for rrows.Next() {
				var rt rate
				if rrows.Scan(&rt.Coins, &rt.Minutes) == nil {
					rates = append(rates, rt)
				}
			}
			rrows.Close()
		}
		devs = append(devs, map[string]any{
			"id": d.id, "name": d.name, "location": d.loc,
			"api_url": d.apiURL, "minutes_per_pulse": d.mpp,
			"enabled": d.enabled == 1, "rates": rates,
		})
	}
	respondJSON(w, http.StatusOK, map[string]any{"devices": devs})
}

var apiURLPattern = regexp.MustCompile(`^https?://[A-Za-z0-9._:\[\]/-]{0,180}$`)

func (s *Store) handleVendoSave(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodPost {
		respondError(w, http.StatusMethodNotAllowed, "method not allowed")
		return
	}
	var in struct {
		ID              int    `json:"id"`
		Name            string `json:"name"`
		Location        string `json:"location"`
		APIURL          string `json:"api_url"`
		MinutesPerPulse int    `json:"minutes_per_pulse"`
		Enabled         bool   `json:"enabled"`
	}
	if !readJSON(w, r, &in) {
		return
	}
	in.Name = strings.TrimSpace(in.Name)
	if in.Name == "" || len(in.Name) > 60 {
		respondError(w, http.StatusBadRequest, "name is required (max 60 chars)")
		return
	}
	in.APIURL = strings.TrimRight(strings.TrimSpace(in.APIURL), "/")
	if in.APIURL != "" && !apiURLPattern.MatchString(in.APIURL) {
		respondError(w, http.StatusBadRequest, "api_url must be an http:// or https:// URL")
		return
	}
	if in.MinutesPerPulse < 1 || in.MinutesPerPulse > 240 {
		respondError(w, http.StatusBadRequest, "minutes_per_pulse must be 1..240")
		return
	}
	enabled := 0
	if in.Enabled {
		enabled = 1
	}
	if in.ID > 0 {
		_, err := s.App.Exec(`UPDATE vendo_devices SET name=?, location=?, api_url=?,
			minutes_per_pulse=?, enabled=? WHERE id=?`,
			in.Name, in.Location, in.APIURL, in.MinutesPerPulse, enabled, in.ID)
		if err != nil {
			respondError(w, http.StatusInternalServerError, err.Error())
			return
		}
	} else {
		res, err := s.App.Exec(`INSERT INTO vendo_devices (name, location, api_url, minutes_per_pulse, enabled)
			VALUES (?, ?, ?, ?, ?)`,
			in.Name, in.Location, in.APIURL, in.MinutesPerPulse, enabled)
		if err != nil {
			respondError(w, http.StatusInternalServerError, err.Error())
			return
		}
		if id64, err := res.LastInsertId(); err == nil {
			in.ID = int(id64)
		}
	}
	respondJSON(w, http.StatusOK, map[string]any{"ok": true, "id": in.ID})
}

func (s *Store) handleVendoRates(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodPost {
		respondError(w, http.StatusMethodNotAllowed, "method not allowed")
		return
	}
	var in struct {
		VendoID int `json:"vendo_id"`
		Rates   []struct {
			Coins   int `json:"coins"`
			Minutes int `json:"minutes"`
		} `json:"rates"`
	}
	if !readJSON(w, r, &in) {
		return
	}
	if in.VendoID < 1 {
		respondError(w, http.StatusBadRequest, "vendo_id required")
		return
	}
	var one int
	if err := s.App.QueryRow("SELECT 1 FROM vendo_devices WHERE id = ?", in.VendoID).Scan(&one); err != nil {
		respondError(w, http.StatusNotFound, "device not found")
		return
	}
	if len(in.Rates) > 12 {
		respondError(w, http.StatusBadRequest, "max 12 rate tiers")
		return
	}
	tx, err := s.App.Begin()
	if err != nil {
		respondError(w, http.StatusInternalServerError, err.Error())
		return
	}
	defer tx.Rollback()
	if _, err := tx.Exec("DELETE FROM vendo_rates WHERE vendo_id = ?", in.VendoID); err != nil {
		respondError(w, http.StatusInternalServerError, err.Error())
		return
	}
	for _, rt := range in.Rates {
		if rt.Coins < 1 || rt.Coins > 50 || rt.Minutes < 1 || rt.Minutes > 1440 {
			respondError(w, http.StatusBadRequest, "coins must be 1..50, minutes 1..1440")
			return
		}
		if _, err := tx.Exec(`INSERT INTO vendo_rates (vendo_id, coins, minutes) VALUES (?, ?, ?)`,
			in.VendoID, rt.Coins, rt.Minutes); err != nil {
			respondError(w, http.StatusInternalServerError, err.Error())
			return
		}
	}
	if err := tx.Commit(); err != nil {
		respondError(w, http.StatusInternalServerError, err.Error())
		return
	}
	respondJSON(w, http.StatusOK, map[string]any{"ok": true})
}

func (s *Store) handleVendoDelete(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodPost {
		respondError(w, http.StatusMethodNotAllowed, "method not allowed")
		return
	}
	var in struct {
		ID int `json:"id"`
	}
	if !readJSON(w, r, &in) {
		return
	}
	if in.ID < 1 {
		respondError(w, http.StatusBadRequest, "id required")
		return
	}
	if _, err := s.App.Exec("DELETE FROM vendo_devices WHERE id = ?", in.ID); err != nil {
		respondError(w, http.StatusInternalServerError, err.Error())
		return
	}
	respondJSON(w, http.StatusOK, map[string]any{"ok": true})
}

// -------------------------------------------------------------- sessions

func (s *Store) handleSessions(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodGet {
		respondError(w, http.StatusMethodNotAllowed, "method not allowed")
		return
	}
	rc := routerActive(s)
	if rc == nil {
		respondJSON(w, http.StatusOK, map[string]any{"router": false, "sessions": []any{}})
		return
	}
	active, err := rc.listActive()
	if err != nil {
		respondError(w, http.StatusBadGateway, "router: "+err.Error())
		return
	}
	out := []map[string]any{}
	for _, a := range active {
		out = append(out, map[string]any{
			"id": fmt.Sprint(a[".id"]), "user": fmt.Sprint(a["user"]),
			"address": fmt.Sprint(a["address"]), "mac": fmt.Sprint(a["mac-address"]),
			"uptime": fmt.Sprint(a["uptime"]), "time_left": fmt.Sprint(a["session-time-left"]),
			"server": fmt.Sprint(a["server"]),
		})
	}
	respondJSON(w, http.StatusOK, map[string]any{"router": true, "sessions": out})
}

func (s *Store) handleKick(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodPost {
		respondError(w, http.StatusMethodNotAllowed, "method not allowed")
		return
	}
	var in struct {
		ID string `json:"id"`
	}
	if !readJSON(w, r, &in) {
		return
	}
	if in.ID == "" {
		respondError(w, http.StatusBadRequest, "id required")
		return
	}
	rc := routerActive(s)
	if rc == nil {
		respondError(w, http.StatusBadRequest, "router not configured")
		return
	}
	if err := rc.kick(in.ID); err != nil {
		respondError(w, http.StatusBadGateway, "router: "+err.Error())
		return
	}
	respondJSON(w, http.StatusOK, map[string]any{"ok": true})
}

// -------------------------------------------------------------- settings

var settingsKeys = []string{
	"portal_name", "sbc_url",
	"rate_limit", "idle_timeout", "interim",
	"radius_secret", "sbc_ip", "sbc_mac",
}

func (s *Store) handleSettings(w http.ResponseWriter, r *http.Request) {
	switch r.Method {
	case http.MethodGet:
		settingsGet(w, r, s)
	case http.MethodPost:
		s.settingsSave(w, r)
	default:
		respondError(w, http.StatusMethodNotAllowed, "method not allowed")
	}
}

func settingsGet(w http.ResponseWriter, _ *http.Request, s *Store) {
	cur := map[string]string{
		"portal_name":   "AIRCOINS NETFI",
		"sbc_url":       "",
		"rate_limit":    "2M/2M",
		"idle_timeout":  "600",
		"interim":       "300",
		"radius_secret": "",
		"sbc_ip":        "",
		"sbc_mac":       "",
	}
	for k, v := range s.settingsAll() {
		cur[k] = v
	}
	group, _ := s.radiusGroupReplies("hotspot")
	respondJSON(w, http.StatusOK, map[string]any{"settings": cur, "group_replies": group})
}

func (s *Store) settingsSave(w http.ResponseWriter, r *http.Request) {
	var in map[string]string
	if !readJSON(w, r, &in) {
		return
	}
	for _, key := range settingsKeys {
		val, ok := in[key]
		if !ok {
			continue
		}
		val = strings.TrimSpace(val)
		switch key {
		case "portal_name":
			if val == "" || len(val) > 40 {
				respondError(w, http.StatusBadRequest, "portal_name must be 1..40 chars")
				return
			}
		case "sbc_url":
			if val != "" {
				u, err := url.Parse(val)
				if err != nil || (u.Scheme != "http" && u.Scheme != "https") || u.Host == "" {
					respondError(w, http.StatusBadRequest, key+" must be http(s)://host")
					return
				}
			}
		case "rate_limit":
			if !ratePattern.MatchString(val) {
				respondError(w, http.StatusBadRequest, "rate_limit must look like 2M/2M")
				return
			}
		case "idle_timeout", "interim":
			if !digitsPattern.MatchString(val) {
				respondError(w, http.StatusBadRequest, key+" must be seconds")
				return
			}
		}
		if err := s.setSetting(key, val); err != nil {
			respondError(w, http.StatusInternalServerError, err.Error())
			return
		}
	}
	// Push the hotspot group's enforced attributes into the RADIUS DB.
	rl := s.getSetting("rate_limit", "2M/2M")
	idle := s.getSetting("idle_timeout", "600")
	interim := s.getSetting("interim", "300")
	if err := s.radiusSetGroupReply("hotspot", map[string]string{
		"Mikrotik-Rate-Limit":   rl,
		"Idle-Timeout":          idle,
		"Acct-Interim-Interval": interim,
	}); err != nil {
		respondError(w, http.StatusInternalServerError, err.Error())
		return
	}
	respondJSON(w, http.StatusOK, map[string]any{"ok": true})
}

// ----------------------------------------------------------------- tools

const stubRemoteDir = "flash/hotspot"
const stubMaxBytes = 3072 // >3KB in the html-directory means a full portal leaked in

func (s *Store) handleStubsStatus(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodGet {
		respondError(w, http.StatusMethodNotAllowed, "method not allowed")
		return
	}
	local, err := stubList()
	if err != nil {
		respondError(w, http.StatusInternalServerError, err.Error())
		return
	}
	out := map[string]any{"router": false, "stubs": []map[string]any{}}
	rc := routerActive(s)
	remote := map[string]int64{}
	if rc == nil {
		s.stubsFill(w, local, remote, out)
		return
	}
	out["router"] = true
	remote, err = rc.remoteStubMap(stubRemoteDir)
	if err != nil {
		out["router_error"] = err.Error()
	}
	s.stubsFill(w, local, remote, out)
}

func (s *Store) stubsFill(w http.ResponseWriter, local map[string][]byte, remote map[string]int64, out map[string]any) {
	names, _ := stubNames()
	list := []map[string]any{}
	for _, name := range names {
		localSize := len(local[name])
		status := "missing"
		remoteSize := any(nil)
		if sz, ok := remote[name]; ok {
			remoteSize = sz
			status = "ok"
			if sz > stubMaxBytes {
				status = "too_large"
			}
		}
		list = append(list, map[string]any{
			"name": name, "local_size": localSize,
			"remote_size": remoteSize, "status": status,
		})
	}
	out["stubs"] = list
	respondJSON(w, http.StatusOK, out)
}

func (s *Store) handleStubsUpload(w http.ResponseWriter, r *http.Request) {
	// DISABLED (operator decision, 2026-10-05): flash/hotspot now hosts the
	// complete self-contained captive portal (repo hotspot/ — panel design,
	// RADIUS PAP login, no redirect chain). Pushing redirect stubs would
	// overwrite the portal pages and re-create the interception redirect
	// loop observed on the live router. Deploy portal files manually via
	// WinBox instead.
	respondError(w, http.StatusGone,
		"stub upload disabled: the router hosts the full captive portal; upload the repo hotspot/ folder via WinBox")
}
