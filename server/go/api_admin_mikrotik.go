package main

import (
	"errors"
	"fmt"
	"net"
	"net/http"
	"net/url"
	"regexp"
	"strings"
	"time"
)

// MikroTik configurator — binds a router to this panel over RouterOS v7
// REST. Every entry we manage is tagged with comment "aircoins" (the
// hotspot profile is named "aircoins"), so apply is idempotent: re-running
// updates in place instead of duplicating.
//
// The binding recipe (hard-won from live deployments):
//   - RADIUS client: service=hotspot, address=SBC IP, shared secret.
//   - Hotspot profile "aircoins": use-radius=yes, login-by=http-pap,cookie
//     (http-chap cannot match Cleartext-Password users and causes login
//     loops), html-directory=flash/hotspot for the thin redirect stubs.
//   - Walled garden: accept tcp/80 to the SBC IP so unauthenticated
//     clients reach the portal (and the NodeMCU cannot be blocked).
//   - IP binding: type=bypassed for the SBC itself so the hotspot never
//     intercepts the panel's own traffic.
const (
	mtComment     = "aircoins"
	mtProfileName = "aircoins"
	mtHTMLDir     = "flash/hotspot"
)

var mtIfacePattern = regexp.MustCompile(`^[A-Za-z0-9._:-]{1,32}$`)

func mtStr(e map[string]any, key string) string {
	if v, ok := e[key]; ok && v != nil {
		return strings.TrimSpace(fmt.Sprint(v))
	}
	return ""
}

func mtBool(v any) bool {
	s := strings.ToLower(strings.TrimSpace(fmt.Sprint(v)))
	return s == "true" || s == "yes"
}

// mtFind returns the first list entry whose key matches want.
func mtFind(entries []map[string]any, key, want string) map[string]any {
	for _, e := range entries {
		if mtStr(e, key) == want {
			return e
		}
	}
	return nil
}

// mtEsc appends a RouterOS object id raw: the literal "*N" is a legal
// URL path character and percent-encoding it has not proven reliable
// on live routers.
func mtEsc(id string) string { return id }

// macColon formats 12-hex into AA:BB:CC:DD:EE:FF (RouterOS expects colons).
func macColon(hex string) string {
	if len(hex) != 12 {
		return hex
	}
	var b strings.Builder
	for i := 0; i < 12; i += 2 {
		if i > 0 {
			b.WriteByte(':')
		}
		b.WriteString(hex[i : i+2])
	}
	return b.String()
}

// mtSBCInfo detects this SBC's address as the router sees it. A UDP
// "dial" writes no packet but makes the kernel pick the source address
// that routes toward the router — exactly the IP the RADIUS client,
// walled garden, and binding rules must use.
func (s *Store) mtSBCInfo(rc *RouterClient) map[string]any {
	out := map[string]any{"ip": "", "mac": "", "url_host": "", "url_is_ip": false}
	if sbcURL := s.getSetting("sbc_url", ""); sbcURL != "" {
		if u, err := url.Parse(sbcURL); err == nil && u.Hostname() != "" {
			host := u.Hostname()
			out["url_host"] = host
			out["url_is_ip"] = net.ParseIP(host) != nil
		}
	}
	if rc != nil {
		if u, err := url.Parse(rc.base); err == nil && u.Hostname() != "" {
			port := u.Port()
			if port == "" {
				if u.Scheme == "https" {
					port = "443"
				} else {
					port = "80"
				}
			}
			if conn, err := net.DialTimeout("udp",
				net.JoinHostPort(u.Hostname(), port), 3*time.Second); err == nil {
				if la, ok := conn.LocalAddr().(*net.UDPAddr); ok {
					out["ip"] = la.IP.String()
				}
				conn.Close()
			}
		}
	}
	// MAC of the interface holding the detected IP (fallback: first NIC
	// with a global IPv4). Best effort — not fatal when unavailable.
	detected, _ := out["ip"].(string)
	if ifaces, err := net.Interfaces(); err == nil {
		fallback := ""
		matched := ""
		for _, ifc := range ifaces {
			if ifc.Flags&net.FlagLoopback != 0 || ifc.HardwareAddr == nil {
				continue
			}
			addrs, err := ifc.Addrs()
			if err != nil {
				continue
			}
			for _, a := range addrs {
				ipn, ok := a.(*net.IPNet)
				if !ok {
					continue
				}
				ip4 := ipn.IP.To4()
				if ip4 == nil || ip4.IsLoopback() || ip4.IsLinkLocalUnicast() {
					continue
				}
				mac := ifc.HardwareAddr.String()
				if mac == "" {
					continue
				}
				if fallback == "" {
					fallback = mac
				}
				if detected != "" && ip4.String() == detected {
					matched = mac
				}
			}
		}
		if matched != "" {
			out["mac"] = matched
		} else {
			out["mac"] = fallback
		}
	}
	return out
}

// ----------------------------------------------------------------- status

type mtCheck struct {
	Key    string `json:"key"`
	Label  string `json:"label"`
	OK     bool   `json:"ok"`
	Detail string `json:"detail"`
	Warn   string `json:"warn,omitempty"`
}

func (s *Store) handleMTStatus(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodGet {
		respondError(w, http.StatusMethodNotAllowed, "method not allowed")
		return
	}
	// The MikroTik configurator drives RouterOS v7 REST exclusively
	// (/rest/radius, /rest/ip/hotspot/...), so it needs the concrete REST
	// client. A native-mode active router yields nil here and is reported
	// as "not reachable" below.
	rc, _ := routerActive(s).(*RouterClient)
	sbc := s.mtSBCInfo(rc)
	out := map[string]any{
		"router_configured": rc != nil,
		"sbc":               sbc,
		"saved": map[string]any{
			"sbc_ip":            s.getSetting("sbc_ip", ""),
			"sbc_mac":           s.getSetting("sbc_mac", ""),
			"sbc_url":           s.getSetting("sbc_url", ""),
			"radius_secret_set": s.getSetting("radius_secret", "") != "",
		},
		"checks":     []mtCheck{},
		"servers":    []map[string]any{},
		"interfaces": []map[string]any{},
	}
	checks := []mtCheck{}
	if rc == nil {
		checks = append(checks, mtCheck{
			Key: "reach", Label: "Router reachable",
			Detail: "Add and activate a REST-mode router in the Routers page first.",
		})
		out["checks"] = checks
		respondJSON(w, http.StatusOK, out)
		return
	}

	detected, _ := sbc["ip"].(string)
	sbcIP := s.getSetting("sbc_ip", "")
	if sbcIP == "" {
		sbcIP = detected
	}

	// reach + version
	var res map[string]any
	if err := rc.json(http.MethodGet, "/rest/system/resource", nil, &res); err != nil {
		checks = append(checks, mtCheck{
			Key: "reach", Label: "Router reachable", Detail: "unreachable: " + err.Error(),
		})
		out["checks"] = checks
		respondJSON(w, http.StatusOK, out)
		return
	}
	checks = append(checks, mtCheck{
		Key: "reach", Label: "Router reachable", OK: true,
		Detail: "RouterOS " + mtStr(res, "version") + " on " + mtStr(res, "board-name"),
	})

	// RADIUS client entry
	var radius []map[string]any
	if err := rc.json(http.MethodGet, "/rest/radius", nil, &radius); err != nil {
		checks = append(checks, mtCheck{Key: "radius", Label: "RADIUS client", Detail: "read failed: " + err.Error()})
	} else {
		ours := mtFind(radius, "comment", mtComment)
		detail := "no aircoins RADIUS entry"
		ok := false
		if ours != nil {
			addr, svc := mtStr(ours, "address"), mtStr(ours, "service")
			ok = strings.Contains(svc, "hotspot")
			detail = "address=" + addr + " service=" + svc
			if ok && sbcIP != "" && addr != sbcIP {
				ok = false
				detail += " (expected " + sbcIP + ")"
			}
		}
		chk := mtCheck{Key: "radius", Label: "RADIUS client", OK: ok, Detail: detail}
		var others []string
		for _, e := range radius {
			if e == nil || e["address"] == nil {
				continue
			}
			if mtStr(e, "comment") == mtComment || !strings.Contains(mtStr(e, "service"), "hotspot") {
				continue
			}
			others = append(others, mtStr(e, "address"))
		}
		if len(others) > 0 {
			chk.Warn = "other hotspot RADIUS servers also configured: " + strings.Join(others, ", ")
		}
		checks = append(checks, chk)
	}

	// hotspot profile
	var profiles []map[string]any
	if err := rc.json(http.MethodGet, "/rest/ip/hotspot/profile", nil, &profiles); err != nil {
		checks = append(checks, mtCheck{Key: "profile", Label: "Hotspot profile", Detail: "read failed: " + err.Error()})
	} else {
		p := mtFind(profiles, "name", mtProfileName)
		detail := "profile \"" + mtProfileName + "\" missing"
		ok := false
		if p != nil {
			loginBy := mtStr(p, "login-by")
			useRadius := mtBool(p["use-radius"])
			htmlDir := mtStr(p, "html-directory")
			ok = useRadius &&
				strings.Contains(loginBy, "http-pap") &&
				strings.Contains(loginBy, "cookie") &&
				htmlDir == mtHTMLDir
			detail = "use-radius=" + boolWord(useRadius) +
				" login-by=" + orNone(loginBy) + " html-directory=" + orNone(htmlDir)
		}
		checks = append(checks, mtCheck{Key: "profile", Label: "Hotspot profile", OK: ok, Detail: detail})
	}

	// hotspot servers + interfaces (also consumed by the UI dropdowns)
	servers := []map[string]any{}
	serverOK := false
	var srvRaw []map[string]any
	if err := rc.json(http.MethodGet, "/rest/ip/hotspot", nil, &srvRaw); err != nil {
		checks = append(checks, mtCheck{Key: "server", Label: "Hotspot server", Detail: "read failed: " + err.Error()})
	} else {
		var names []string
		for _, e := range srvRaw {
			entry := map[string]any{
				"id": mtStr(e, ".id"), "interface": mtStr(e, "interface"),
				"profile": mtStr(e, "profile"), "disabled": mtBool(e["disabled"]),
			}
			servers = append(servers, entry)
			if !entry["disabled"].(bool) && entry["profile"] == mtProfileName {
				serverOK = true
			}
			names = append(names, entry["interface"].(string))
		}
		checks = append(checks, mtCheck{
			Key: "server", Label: "Hotspot server", OK: serverOK,
			Detail: orNone(strings.Join(names, ", ")),
		})
	}
	out["servers"] = servers

	ifaces := []map[string]any{}
	var ifRaw []map[string]any
	if err := rc.json(http.MethodGet, "/rest/interface", nil, &ifRaw); err == nil {
		for _, e := range ifRaw {
			ifaces = append(ifaces, map[string]any{
				"name": mtStr(e, "name"), "type": mtStr(e, "type"),
				"running": mtBool(e["running"]), "disabled": mtBool(e["disabled"]),
			})
		}
	}
	out["interfaces"] = ifaces

	// walled garden
	var garden []map[string]any
	if err := rc.json(http.MethodGet, "/rest/ip/hotspot/walled-garden/ip", nil, &garden); err != nil {
		checks = append(checks, mtCheck{Key: "garden", Label: "Walled garden", Detail: "read failed: " + err.Error()})
	} else {
		detail, ok := "no aircoins accept rule", false
		for _, e := range garden {
			if mtStr(e, "comment") != mtComment {
				continue
			}
			detail = fmt.Sprintf("action=%s dst=%s proto=%s port=%s",
				orNone(mtStr(e, "action")), orNone(mtStr(e, "dst-address")),
				orNone(mtStr(e, "protocol")), orNone(mtStr(e, "dst-port")))
			ok = mtStr(e, "action") == "accept" &&
				(sbcIP == "" || mtStr(e, "dst-address") == sbcIP)
			break
		}
		checks = append(checks, mtCheck{Key: "garden", Label: "Walled garden (portal before login)", OK: ok, Detail: detail})
	}

	// ip binding
	var binding []map[string]any
	if err := rc.json(http.MethodGet, "/rest/ip/hotspot/ip-binding", nil, &binding); err != nil {
		checks = append(checks, mtCheck{Key: "binding", Label: "IP binding (SBC bypass)", Detail: "read failed: " + err.Error()})
	} else {
		detail, ok := "no aircoins bypass entry", false
		for _, e := range binding {
			if mtStr(e, "comment") != mtComment {
				continue
			}
			detail = "type=" + orNone(mtStr(e, "type")) + " address=" + orNone(mtStr(e, "address"))
			ok = mtStr(e, "type") == "bypassed" && (sbcIP == "" || mtStr(e, "address") == sbcIP)
			break
		}
		checks = append(checks, mtCheck{Key: "binding", Label: "IP binding (SBC bypass)", OK: ok, Detail: detail})
	}

	// login services (REST rides on www / www-ssl)
	var services []map[string]any
	if err := rc.json(http.MethodGet, "/rest/ip/service", nil, &services); err != nil {
		checks = append(checks, mtCheck{Key: "service", Label: "Login/API service", Detail: "read failed: " + err.Error()})
	} else {
		www, ssl := false, false
		for _, e := range services {
			switch mtStr(e, "name") {
			case "www":
				www = !mtBool(e["disabled"])
			case "www-ssl":
				ssl = !mtBool(e["disabled"])
			}
		}
		detail := "www=" + boolWord(www) + " www-ssl=" + boolWord(ssl)
		if !www && !ssl {
			detail += " — REST API needs one of these enabled"
		}
		checks = append(checks, mtCheck{Key: "service", Label: "Login/API service", OK: www || ssl, Detail: detail})
	}

	out["checks"] = checks
	respondJSON(w, http.StatusOK, out)
}

func boolWord(b bool) string {
	if b {
		return "yes"
	}
	return "no"
}

func orNone(s string) string {
	if s == "" {
		return "(none)"
	}
	return s
}

// ------------------------------------------------------------------ apply

func (s *Store) handleMTApply(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodPost {
		respondError(w, http.StatusMethodNotAllowed, "method not allowed")
		return
	}
	var in struct {
		SBCIP        string `json:"sbc_ip"`
		MAC          string `json:"sbc_mac"`
		Secret       string `json:"secret"`
		Interface    string `json:"interface"`
		CreateServer bool   `json:"create_server"`
	}
	if !readJSON(w, r, &in) {
		return
	}
	parsed := net.ParseIP(strings.TrimSpace(in.SBCIP))
	if parsed == nil || parsed.To4() == nil {
		respondError(w, http.StatusBadRequest, "sbc_ip must be an IPv4 address")
		return
	}
	ip := parsed.String()
	secret := strings.TrimSpace(in.Secret)
	if len(secret) < 8 || len(secret) > 64 || strings.ContainsAny(secret, " \t\r\n") {
		respondError(w, http.StatusBadRequest, "secret must be 8..64 chars without spaces")
		return
	}
	iface := strings.TrimSpace(in.Interface)
	if !mtIfacePattern.MatchString(iface) {
		respondError(w, http.StatusBadRequest, "interface name looks invalid")
		return
	}
	mac := ""
	if strings.TrimSpace(in.MAC) != "" {
		mac = normalizeMAC(in.MAC)
		if mac == "" {
			respondError(w, http.StatusBadRequest, "sbc_mac must be a MAC address")
			return
		}
	}
	// REST-only configurator: a native-mode active router yields nil here.
	rc, _ := routerActive(s).(*RouterClient)
	if rc == nil {
		respondError(w, http.StatusBadRequest, "activate a REST-mode router in the Routers page first")
		return
	}

	results := []map[string]any{}
	failed := 0
	step := func(label string, err error, detail string) {
		e := map[string]any{"label": label, "ok": err == nil}
		if err != nil {
			e["error"] = err.Error()
			failed++
		} else if detail != "" {
			e["detail"] = detail
		}
		results = append(results, e)
	}

	// 1. RADIUS client entry (this panel as the auth server).
	var radius []map[string]any
	if err := rc.json(http.MethodGet, "/rest/radius", nil, &radius); err != nil {
		step("RADIUS client entry", err, "")
	} else {
		body := map[string]any{
			"service": "hotspot", "address": ip, "secret": secret,
			"accounting": true, "comment": mtComment,
		}
		if ours := mtFind(radius, "comment", mtComment); ours != nil {
			step("RADIUS client entry (update)",
				rc.json(http.MethodPatch, "/rest/radius/"+mtEsc(mtStr(ours, ".id")), body, nil),
				"hotspot -> "+ip)
		} else {
			step("RADIUS client entry (create)",
				rc.json(http.MethodPut, "/rest/radius", body, nil),
				"hotspot -> "+ip)
		}
	}

	// 2. hotspot profile with PAP login (CHAP would reject every login).
	pDetail := "use-radius=yes login-by=http-pap,cookie html-directory=" + mtHTMLDir
	var profiles []map[string]any
	if err := rc.json(http.MethodGet, "/rest/ip/hotspot/profile", nil, &profiles); err != nil {
		step("Hotspot profile", err, "")
	} else {
		pbody := map[string]any{
			"use-radius": true, "login-by": "http-pap,cookie",
			"html-directory": mtHTMLDir,
		}
		if p := mtFind(profiles, "name", mtProfileName); p != nil {
			step("Hotspot profile (update)",
				rc.json(http.MethodPatch, "/rest/ip/hotspot/profile/"+mtEsc(mtStr(p, ".id")), pbody, nil), pDetail)
		} else {
			pbody["name"] = mtProfileName
			step("Hotspot profile (create)",
				rc.json(http.MethodPut, "/rest/ip/hotspot/profile", pbody, nil), pDetail)
		}
	}

	// 3. hotspot server on the chosen interface uses our profile.
	var servers []map[string]any
	if err := rc.json(http.MethodGet, "/rest/ip/hotspot", nil, &servers); err != nil {
		step("Hotspot server", err, "")
	} else {
		var mine []map[string]any
		for _, e := range servers {
			if mtStr(e, "interface") == iface {
				mine = append(mine, e)
			}
		}
		switch {
		case len(mine) == 0 && in.CreateServer:
			step("Hotspot server (create)",
				rc.json(http.MethodPut, "/rest/ip/hotspot",
					map[string]any{"interface": iface, "profile": mtProfileName}, nil),
				"interface "+iface+" (make sure DHCP serves that interface)")
		case len(mine) == 0:
			step("Hotspot server",
				errors.New("no hotspot server on interface "+iface+
					" — pick another interface or tick 'create server'"), "")
		default:
			var first error
			for _, e := range mine {
				path := "/rest/ip/hotspot/" + mtEsc(mtStr(e, ".id"))
				if mtStr(e, "profile") != mtProfileName {
					if err := rc.json(http.MethodPatch, path,
						map[string]any{"profile": mtProfileName}, nil); err != nil && first == nil {
						first = err
					}
				}
				if mtBool(e["disabled"]) {
					if err := rc.json(http.MethodPatch, path,
						map[string]any{"disabled": false}, nil); err != nil && first == nil {
						first = err
					}
				}
			}
			step(fmt.Sprintf("Hotspot server on %s (%d found)", iface, len(mine)), first,
				"profile="+mtProfileName)
		}
	}

	// 4. walled garden: unauthenticated clients must reach the portal.
	gardenPath := "/rest/ip/hotspot/walled-garden/ip"
	var garden []map[string]any
	if err := rc.json(http.MethodGet, gardenPath, nil, &garden); err != nil {
		step("Walled garden", err, "")
	} else {
		var delErr error
		for _, e := range garden {
			if mtStr(e, "comment") == mtComment {
				if err := rc.json(http.MethodDelete, gardenPath+"/"+mtEsc(mtStr(e, ".id")), nil, nil); err != nil && delErr == nil {
					delErr = err
				}
			}
		}
		if delErr != nil {
			step("Walled garden (replace old rules)", delErr, "")
		} else {
			gDetail := "accept tcp/80 to " + ip
			err := rc.json(http.MethodPut, gardenPath, map[string]any{
				"action": "accept", "dst-address": ip, "protocol": "tcp",
				"dst-port": "80", "comment": mtComment,
			}, nil)
			// Portal served under a hostname instead of the raw IP? Add a
			// host rule too so the stub redirect target resolves.
			host := ""
			if u, perr := url.Parse(s.getSetting("sbc_url", "")); perr == nil {
				host = u.Hostname()
			}
			if err == nil && host != "" && net.ParseIP(host) == nil {
				err = rc.json(http.MethodPut, gardenPath, map[string]any{
					"action": "accept", "dst-host": host, "comment": mtComment,
				}, nil)
				gDetail += " + host " + host
			}
			step("Walled garden (portal before login)", err, gDetail)
		}
	}

	// 5. IP binding: the SBC itself bypasses the hotspot entirely.
	bindPath := "/rest/ip/hotspot/ip-binding"
	var binding []map[string]any
	if err := rc.json(http.MethodGet, bindPath, nil, &binding); err != nil {
		step("IP binding (SBC bypass)", err, "")
	} else {
		var delErr error
		for _, e := range binding {
			if mtStr(e, "comment") == mtComment {
				if err := rc.json(http.MethodDelete, bindPath+"/"+mtEsc(mtStr(e, ".id")), nil, nil); err != nil && delErr == nil {
					delErr = err
				}
			}
		}
		if delErr != nil {
			step("IP binding (replace old entries)", delErr, "")
		} else {
			entry := map[string]any{
				"type": "bypassed", "address": ip, "comment": mtComment,
			}
			detail := "bypassed " + ip
			if mac != "" {
				entry["mac-address"] = macColon(mac)
				detail += " mac " + macColon(mac)
			}
			step("IP binding (SBC bypass)",
				rc.json(http.MethodPut, bindPath, entry, nil), detail)
		}
	}

	// 6. persist to panel settings so other features reuse the values.
	var saveErr error
	for key, val := range map[string]string{
		"sbc_ip": ip, "radius_secret": secret,
	} {
		if err := s.setSetting(key, val); err != nil && saveErr == nil {
			saveErr = err
		}
	}
	if mac != "" {
		if err := s.setSetting("sbc_mac", mac); err != nil && saveErr == nil {
			saveErr = err
		}
	}
	step("Save panel settings", saveErr, "sbc_ip and radius_secret stored")
	if saveErr != nil {
		failed++
	}

	respondJSON(w, http.StatusOK, map[string]any{"ok": failed == 0, "results": results})
}
