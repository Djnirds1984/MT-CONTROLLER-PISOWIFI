package main

import (
	"net/http"
	"regexp"
	"strings"
	"time"
)

var (
	usernamePattern = regexp.MustCompile(`^[A-Za-z0-9._@-]{1,64}$`)
	prefixPattern   = regexp.MustCompile(`^[A-Za-z0-9]{0,8}$`)
	ratePattern     = regexp.MustCompile(`^[0-9]+[kKmM]?/[0-9]+[kKmM]?$`)
	digitsPattern   = regexp.MustCompile(`^[0-9]+$`)
)

// adminGuard requires a valid admin session cookie; mutating calls must
// also carry the X-Aircoins-Auth header (cross-site forms cannot set it).
func (s *Store) adminGuard(next http.HandlerFunc) http.HandlerFunc {
	return func(w http.ResponseWriter, r *http.Request) {
		switch r.Method {
		case http.MethodPost, http.MethodPut, http.MethodPatch, http.MethodDelete:
			if r.Header.Get("X-Aircoins-Auth") == "" {
				respondError(w, http.StatusForbidden, "missing CSRF header")
				return
			}
		}
		c, err := r.Cookie(adminCookieName)
		if err != nil || c.Value == "" {
			respondError(w, http.StatusUnauthorized, "not logged in")
			return
		}
		if _, ok := s.sessionValid(c.Value); !ok {
			respondError(w, http.StatusUnauthorized, "session expired")
			return
		}
		next(w, r)
	}
}

func (s *Store) handleAdminLogin(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodPost {
		respondError(w, http.StatusMethodNotAllowed, "method not allowed")
		return
	}
	ip := clientIP(r)
	if s.throttle.blocked(ip) {
		respondError(w, http.StatusTooManyRequests, "too many failed attempts; wait 5 minutes")
		return
	}
	var in struct {
		Username string `json:"username"`
		Password string `json:"password"`
	}
	if !readJSON(w, r, &in) {
		return
	}
	ok, err := s.adminVerify(strings.TrimSpace(in.Username), in.Password)
	if err != nil {
		respondError(w, http.StatusInternalServerError, "login check failed")
		return
	}
	if !ok {
		s.throttle.fail(ip)
		respondError(w, http.StatusUnauthorized, "invalid credentials")
		return
	}
	s.throttle.reset(ip)
	_ = s.sessionGC()
	token, err := s.sessionCreate(strings.TrimSpace(in.Username))
	if err != nil {
		respondError(w, http.StatusInternalServerError, "could not create session")
		return
	}
	secure := r.Header.Get("X-Forwarded-Proto") == "https" || r.TLS != nil
	http.SetCookie(w, &http.Cookie{
		Name:     adminCookieName,
		Value:    token,
		Path:     "/",
		HttpOnly: true,
		SameSite: http.SameSiteLaxMode,
		MaxAge:   sessionDays * 86400,
		Secure:   secure,
	})
	respondJSON(w, http.StatusOK, map[string]any{"ok": true, "username": strings.TrimSpace(in.Username)})
}

func (s *Store) handleAdminLogout(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodPost {
		respondError(w, http.StatusMethodNotAllowed, "method not allowed")
		return
	}
	if c, err := r.Cookie(adminCookieName); err == nil {
		_ = s.sessionDelete(c.Value)
	}
	http.SetCookie(w, &http.Cookie{
		Name: adminCookieName, Value: "", Path: "/", MaxAge: -1,
		HttpOnly: true, SameSite: http.SameSiteLaxMode,
	})
	respondJSON(w, http.StatusOK, map[string]any{"ok": true})
}

// handleOverview: dashboard counters + recent coin activity + live count.
func (s *Store) handleOverview(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodGet {
		respondError(w, http.StatusMethodNotAllowed, "method not allowed")
		return
	}
	out := map[string]any{
		"portal_name":       s.getSetting("portal_name", "AIRCOINS NETFI"),
		"router_configured": routerFromSettings(s) != nil,
	}
	var n int
	if err := s.App.QueryRow("SELECT COUNT(*) FROM vouchers").Scan(&n); err == nil {
		out["vouchers"] = n
	}
	if err := s.Radius.QueryRow("SELECT COUNT(DISTINCT username) FROM radcheck").Scan(&n); err == nil {
		out["radius_users"] = n
	}
	var coins, mins int
	if err := s.App.QueryRow(`SELECT COUNT(*), COALESCE(SUM(minutes), 0)
		FROM coin_log WHERE created_at >= date('now')`).Scan(&coins, &mins); err == nil {
		out["coins_today"] = coins
		out["minutes_today"] = mins
	}
	recent := []map[string]any{}
	rows, err := s.App.Query(`SELECT mac, coins, minutes, created_at
		FROM coin_log ORDER BY id DESC LIMIT 10`)
	if err == nil {
		for rows.Next() {
			var mac, created string
			var c, m int
			if rows.Scan(&mac, &c, &m, &created) == nil {
				recent = append(recent, map[string]any{
					"mac": mac, "coins": c, "minutes": m, "created_at": created,
				})
			}
		}
		rows.Close()
	}
	out["recent_coins"] = recent
	if rc := routerFromSettings(s); rc != nil {
		if active, err := rc.listActive(); err == nil {
			out["online_sessions"] = len(active)
		}
	}
	respondJSON(w, http.StatusOK, out)
}

// ------------------------------------------------------------- vouchers

func (s *Store) handleVouchers(w http.ResponseWriter, r *http.Request) {
	switch r.Method {
	case http.MethodGet:
		s.vouchersList(w, r)
	case http.MethodPost:
		s.vouchersGenerate(w, r)
	default:
		respondError(w, http.StatusMethodNotAllowed, "method not allowed")
	}
}

func (s *Store) vouchersList(w http.ResponseWriter, r *http.Request) {
	type vrow struct {
		code      string
		minutes   int
		batch     string
		createdAt string
	}
	var rowsData []vrow
	rows, err := s.App.Query(`SELECT code, minutes, batch, created_at
		FROM vouchers ORDER BY id DESC LIMIT 500`)
	if err != nil {
		respondError(w, http.StatusInternalServerError, err.Error())
		return
	}
	for rows.Next() {
		var v vrow
		if rows.Scan(&v.code, &v.minutes, &v.batch, &v.createdAt) == nil {
			rowsData = append(rowsData, v)
		}
	}
	rows.Close()

	codes := make([]string, len(rowsData))
	for i, v := range rowsData {
		codes[i] = v.code
	}
	used, err := s.radiusUsedMap(codes)
	if err != nil {
		respondError(w, http.StatusInternalServerError, err.Error())
		return
	}
	out := []map[string]any{}
	for _, v := range rowsData {
		entry := map[string]any{
			"code": v.code, "minutes": v.minutes, "batch": v.batch,
			"created_at": v.createdAt, "used": false,
			"first_use": nil, "sessions": 0,
		}
		if u, ok := used[v.code]; ok {
			entry["used"] = u["used"]
			entry["first_use"] = u["first_use"]
			entry["sessions"] = u["sessions"]
		}
		out = append(out, entry)
	}
	respondJSON(w, http.StatusOK, map[string]any{"vouchers": out})
}

func (s *Store) vouchersGenerate(w http.ResponseWriter, r *http.Request) {
	var in struct {
		Count   int    `json:"count"`
		Minutes int    `json:"minutes"`
		Prefix  string `json:"prefix"`
	}
	if !readJSON(w, r, &in) {
		return
	}
	if in.Count < 1 || in.Count > 500 {
		respondError(w, http.StatusBadRequest, "count must be 1..500")
		return
	}
	if in.Minutes < 5 || in.Minutes > 43200 {
		respondError(w, http.StatusBadRequest, "minutes must be 5..43200")
		return
	}
	in.Prefix = strings.ToUpper(strings.TrimSpace(in.Prefix))
	if !prefixPattern.MatchString(in.Prefix) {
		respondError(w, http.StatusBadRequest, "prefix must be letters/digits, max 8")
		return
	}
	batch := time.Now().UTC().Format("20060102-150405")
	codes := make([]string, 0, in.Count)
	for i := 0; i < in.Count; i++ {
		var code string
		for try := 0; try < 5; try++ {
			if in.Prefix == "" {
				code = randCode(10)
			} else {
				code = in.Prefix + "-" + randCode(6)
			}
			var one int
			err := s.App.QueryRow("SELECT 1 FROM vouchers WHERE code = ?", code).Scan(&one)
			if err != nil {
				break // not found -> usable
			}
		}
		if err := s.voucherCreate(code, in.Minutes, batch); err != nil {
			respondError(w, http.StatusInternalServerError, "generate failed at "+code+": "+err.Error())
			return
		}
		codes = append(codes, code)
	}
	respondJSON(w, http.StatusOK, map[string]any{
		"ok": true, "generated": len(codes), "batch": batch, "codes": codes,
	})
}

func (s *Store) handleVoucherDelete(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodPost {
		respondError(w, http.StatusMethodNotAllowed, "method not allowed")
		return
	}
	var in struct {
		Code string `json:"code"`
	}
	if !readJSON(w, r, &in) {
		return
	}
	in.Code = strings.TrimSpace(in.Code)
	if !usernamePattern.MatchString(in.Code) {
		respondError(w, http.StatusBadRequest, "invalid code")
		return
	}
	if err := s.radiusRemoveUser(in.Code); err != nil {
		respondError(w, http.StatusInternalServerError, err.Error())
		return
	}
	if _, err := s.App.Exec("DELETE FROM vouchers WHERE code = ?", in.Code); err != nil {
		respondError(w, http.StatusInternalServerError, err.Error())
		return
	}
	respondJSON(w, http.StatusOK, map[string]any{"ok": true})
}

// ---------------------------------------------------------------- users

func (s *Store) handleUsers(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodGet {
		respondError(w, http.StatusMethodNotAllowed, "method not allowed")
		return
	}
	filter := r.URL.Query().Get("filter")
	if filter == "" {
		filter = "%"
	}
	// Voucher membership is used to tag kinds; fetch before opening the
	// radius result set (single connection per database).
	voucherSet := map[string]bool{}
	rows, err := s.App.Query("SELECT code FROM vouchers")
	if err == nil {
		for rows.Next() {
			var code string
			if rows.Scan(&code) == nil {
				voucherSet[code] = true
			}
		}
		rows.Close()
	}
	users, err := s.radiusListUsers(filter)
	if err != nil {
		respondError(w, http.StatusInternalServerError, err.Error())
		return
	}
	for _, u := range users {
		if name, ok := u["username"].(string); ok && voucherSet[name] {
			u["kind"] = "voucher"
		}
	}
	respondJSON(w, http.StatusOK, map[string]any{"users": users})
}

func (s *Store) handleUserSave(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodPost {
		respondError(w, http.StatusMethodNotAllowed, "method not allowed")
		return
	}
	var in struct {
		Username string `json:"username"`
		Password string `json:"password"`
		Minutes  int    `json:"minutes"`
		Extend   bool   `json:"extend"`
	}
	if !readJSON(w, r, &in) {
		return
	}
	username := strings.TrimSpace(in.Username)
	if norm := normalizeMAC(username); norm != "" {
		username = norm
	}
	if !usernamePattern.MatchString(username) {
		respondError(w, http.StatusBadRequest, "username must be 1..64 letters/digits/._@-")
		return
	}
	if in.Minutes < 5 || in.Minutes > 43200 {
		respondError(w, http.StatusBadRequest, "minutes must be 5..43200")
		return
	}
	if in.Extend {
		_, err := s.radiusExtendUser(username, in.Minutes*60, "hotspot")
		if err != nil {
			respondError(w, http.StatusInternalServerError, err.Error())
			return
		}
	} else {
		if err := s.radiusAddUser(username, in.Password, in.Minutes*60, "hotspot"); err != nil {
			respondError(w, http.StatusInternalServerError, err.Error())
			return
		}
	}
	respondJSON(w, http.StatusOK, map[string]any{"ok": true, "username": username})
}

func (s *Store) handleUserDelete(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodPost {
		respondError(w, http.StatusMethodNotAllowed, "method not allowed")
		return
	}
	var in struct {
		Username string `json:"username"`
	}
	if !readJSON(w, r, &in) {
		return
	}
	in.Username = strings.TrimSpace(in.Username)
	if !usernamePattern.MatchString(in.Username) {
		respondError(w, http.StatusBadRequest, "invalid username")
		return
	}
	if err := s.radiusRemoveUser(in.Username); err != nil {
		respondError(w, http.StatusInternalServerError, err.Error())
		return
	}
	_, _ = s.App.Exec("DELETE FROM vouchers WHERE code = ?", in.Username)
	respondJSON(w, http.StatusOK, map[string]any{"ok": true})
}
