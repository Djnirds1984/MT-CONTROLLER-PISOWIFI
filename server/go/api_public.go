package main

import (
	"encoding/json"
	"fmt"
	"log"
	"net"
	"net/http"
	"strings"
)

// ---------------------------------------------------------------- helpers

func respondJSON(w http.ResponseWriter, code int, v any) {
	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	w.WriteHeader(code)
	_ = json.NewEncoder(w).Encode(v)
}

func respondError(w http.ResponseWriter, code int, msg string) {
	respondJSON(w, code, map[string]any{"ok": false, "error": msg})
}

// readJSON decodes a small JSON body into out.
func readJSON(w http.ResponseWriter, r *http.Request, out any) bool {
	r.Body = http.MaxBytesReader(w, r.Body, 64<<10)
	dec := json.NewDecoder(r.Body)
	if err := dec.Decode(out); err != nil {
		respondError(w, http.StatusBadRequest, "invalid JSON body: "+err.Error())
		return false
	}
	return true
}

// clientIP prefers the proxy header lighttpd sets, then the peer address.
func clientIP(r *http.Request) string {
	if xf := r.Header.Get("X-Forwarded-For"); xf != "" {
		parts := strings.Split(xf, ",")
		return strings.TrimSpace(parts[0])
	}
	host, _, err := net.SplitHostPort(r.RemoteAddr)
	if err != nil {
		return r.RemoteAddr
	}
	return host
}

// ---------------------------------------------------------------- public

func (s *Store) handleHealth(w http.ResponseWriter, r *http.Request) {
	respondJSON(w, http.StatusOK, map[string]any{"ok": true, "service": "aircoind"})
}

// handleInsertCoin is the NodeMCU vendo firmware (v4) contract:
//
//	POST /api/insertCoin.php?mac=AABBCCDDEEFF&coins=N
//	success: {"status":"true","coins":N,"time_added":"15m","mac":"...","detail":"..."}
//	failure: {"status":"false","error":"...","detail":"..."}
//
// Always HTTP 200 — the firmware decides on the JSON "status" string.
func (s *Store) handleInsertCoin(w http.ResponseWriter, r *http.Request) {
	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	fail := func(code, detail string) {
		_ = json.NewEncoder(w).Encode(map[string]any{
			"status": "false", "error": code, "detail": detail,
		})
	}

	mac := normalizeMAC(r.URL.Query().Get("mac"))
	if mac == "" {
		fail("invalid_mac", "MAC must be 12 hex characters")
		return
	}
	coins := parseIntSafe(r.URL.Query().Get("coins"))
	if coins < 1 || coins > 30 {
		fail("invalid_coins", "coins must be between 1 and 30")
		return
	}

	minutes := s.defaultPulseMinutes()
	added := int(coins) * minutes
	total, err := s.radiusExtendUser(mac, added*60, "hotspot")
	if err != nil {
		log.Printf("insertCoin: extend %s: %v", mac, err)
		fail("sbc_error", "could not update RADIUS budget")
		return
	}
	if _, err := s.App.Exec(`INSERT INTO coin_log (mac, coins, minutes) VALUES (?, ?, ?)`,
		mac, coins, added); err != nil {
		log.Printf("insertCoin: log %s: %v", mac, err)
	}
	_ = json.NewEncoder(w).Encode(map[string]any{
		"status":     "true",
		"coins":      coins,
		"time_added": fmt.Sprintf("%dm", added),
		"mac":        mac,
		"detail":     "Balance: " + secondsLabel(total),
	})
}

// defaultPulseMinutes is the minutes-per-pulse of the first enabled vendo
// device (firmware default 15 when nothing is configured).
func (s *Store) defaultPulseMinutes() int {
	var m int
	err := s.App.QueryRow(`SELECT minutes_per_pulse FROM vendo_devices
		WHERE enabled = 1 ORDER BY id LIMIT 1`).Scan(&m)
	if err != nil || m <= 0 {
		return 15
	}
	return m
}

// handleVendo feeds the portal: site name + enabled devices + promo tiers.
// Query ordering note: the store runs with one connection, so all rate
// lookups happen AFTER the device rows are fully drained.
func (s *Store) handleVendo(w http.ResponseWriter, r *http.Request) {
	type rate struct {
		Coins   int `json:"coins"`
		Minutes int `json:"minutes"`
	}
	type device struct {
		ID              int    `json:"id"`
		Name            string `json:"name"`
		Location        string `json:"location"`
		APIURL          string `json:"api_url"`
		MinutesPerPulse int    `json:"minutes_per_pulse"`
		Rates           []rate `json:"rates"`
	}
	type devRow struct {
		id   int
		name string
		loc  string
		url  string
		mpp  int
	}
	var found []devRow
	rows, err := s.App.Query(`SELECT id, name, location, api_url, minutes_per_pulse
		FROM vendo_devices WHERE enabled = 1 ORDER BY id`)
	if err == nil {
		for rows.Next() {
			var d devRow
			if rows.Scan(&d.id, &d.name, &d.loc, &d.url, &d.mpp) == nil {
				found = append(found, d)
			}
		}
		rows.Close()
	}
	devs := []device{}
	for _, d := range found {
		dev := device{ID: d.id, Name: d.name, Location: d.loc, APIURL: d.url,
			MinutesPerPulse: d.mpp, Rates: []rate{}}
		rrows, rerr := s.App.Query(`SELECT coins, minutes FROM vendo_rates
			WHERE vendo_id = ? ORDER BY coins`, d.id)
		if rerr == nil {
			for rrows.Next() {
				var rt rate
				if rrows.Scan(&rt.Coins, &rt.Minutes) == nil {
					dev.Rates = append(dev.Rates, rt)
				}
			}
			rrows.Close()
		}
		devs = append(devs, dev)
	}
	respondJSON(w, http.StatusOK, map[string]any{
		"portal_name": s.getSetting("portal_name", "AIRCOINS NETFI"),
		"devices":     devs,
	})
}

// handleSession reports the caller's live hotspot session via the router
// REST API (best effort: degrades to online:null without router settings).
func (s *Store) handleSession(w http.ResponseWriter, r *http.Request) {
	ip := r.URL.Query().Get("ip")
	if ip == "" {
		ip = clientIP(r)
	}
	out := map[string]any{
		"portal_name": s.getSetting("portal_name", "AIRCOINS NETFI"),
		"ip":          ip,
		"online":      nil,
	}
	rc := routerFromSettings(s)
	if rc == nil {
		respondJSON(w, http.StatusOK, out)
		return
	}
	active, err := rc.listActive()
	if err != nil {
		respondJSON(w, http.StatusOK, out)
		return
	}
	matched := false
	for _, a := range active {
		if fmt.Sprint(a["address"]) == ip {
			matched = true
			out["online"] = true
			out["user"] = fmt.Sprint(a["user"])
			out["mac"] = fmt.Sprint(a["mac-address"])
			out["uptime"] = fmt.Sprint(a["uptime"])
			if tl, ok := a["session-time-left"]; ok {
				out["time_left"] = fmt.Sprint(tl)
			}
			break
		}
	}
	if !matched {
		out["online"] = false
	}
	respondJSON(w, http.StatusOK, out)
}

func secondsLabel(total int) string {
	h := total / 3600
	m := (total % 3600) / 60
	sec := total % 60
	return fmt.Sprintf("%d:%02d:%02d", h, m, sec)
}
