// aircoind — AIRCOINS NETFI SBC backend service.
//
// A single static Go binary that serves the JSON API on a loopback port.
// lighttpd fronts everything on port 80: static pure-HTML portal at /,
// the admin SPA under /admin (login-protected), and /api/ proxied to
// this process. Authentication authority is FreeRADIUS on the same
// host, reading the same SQLite radius.db this service writes
// (radcheck / radreply / radusergroup).
package main

import (
	"flag"
	"fmt"
	"log"
	"net/http"
	"os"
	"strings"
	"time"
)

var (
	listen  = flag.String("listen", "127.0.0.1:8088", "API listen address (loopback, behind lighttpd)")
	dataDir = flag.String("data", "/var/lib/aircoins", "data directory holding aircoins.db and radius.db")
	adminUp = flag.String("admin", "", `one-shot admin credential upsert "user:password", then exit`)
)

func main() {
	flag.Parse()
	log.SetFlags(log.LstdFlags)

	store, err := openStore(*dataDir)
	if err != nil {
		log.Fatalf("open store: %v", err)
	}

	if *adminUp != "" {
		parts := strings.SplitN(*adminUp, ":", 2)
		if len(parts) != 2 || parts[0] == "" || parts[1] == "" {
			fmt.Fprintln(os.Stderr, "-admin expects user:password")
			os.Exit(2)
		}
		if err := store.adminUpsert(parts[0], parts[1]); err != nil {
			log.Fatalf("admin upsert: %v", err)
		}
		log.Printf("admin user %q credentials updated", parts[0])
		return
	}

	if err := store.sessionGC(); err != nil {
		log.Printf("session gc: %v", err)
	}

	srv := &http.Server{
		Addr:              *listen,
		Handler:           buildMux(store),
		ReadHeaderTimeout: 10 * time.Second,
		ReadTimeout:       45 * time.Second,
		WriteTimeout:      45 * time.Second,
		IdleTimeout:       120 * time.Second,
	}
	log.Printf("aircoind listening on http://%s (data: %s)", *listen, *dataDir)
	log.Fatal(srv.ListenAndServe())
}

func buildMux(s *Store) http.Handler {
	mux := http.NewServeMux()

	// Public API (reachable by hotspot clients and the NodeMCU vendo).
	mux.HandleFunc("/api/health", s.handleHealth)
	mux.HandleFunc("/api/insertCoin.php", s.handleInsertCoin) // firmware v4 contract path
	mux.HandleFunc("/api/insertcoin", s.handleInsertCoin)
	mux.HandleFunc("/api/vendo", s.handleVendo)
	mux.HandleFunc("/api/session", s.handleSession)

	// Admin API (cookie session + CSRF header; same origin behind lighttpd).
	mux.HandleFunc("/api/admin/login", s.handleAdminLogin)
	mux.HandleFunc("/api/admin/logout", s.adminGuard(s.handleAdminLogout))
	mux.HandleFunc("/api/admin/overview", s.adminGuard(s.handleOverview))
	mux.HandleFunc("/api/admin/vouchers", s.adminGuard(s.handleVouchers))
	mux.HandleFunc("/api/admin/vouchers/delete", s.adminGuard(s.handleVoucherDelete))
	mux.HandleFunc("/api/admin/users", s.adminGuard(s.handleUsers))
	mux.HandleFunc("/api/admin/users/save", s.adminGuard(s.handleUserSave))
	mux.HandleFunc("/api/admin/users/delete", s.adminGuard(s.handleUserDelete))
	mux.HandleFunc("/api/admin/vendo", s.adminGuard(s.handleVendoAdmin))
	mux.HandleFunc("/api/admin/vendo/save", s.adminGuard(s.handleVendoSave))
	mux.HandleFunc("/api/admin/vendo/rates", s.adminGuard(s.handleVendoRates))
	mux.HandleFunc("/api/admin/vendo/delete", s.adminGuard(s.handleVendoDelete))
	mux.HandleFunc("/api/admin/sessions", s.adminGuard(s.handleSessions))
	mux.HandleFunc("/api/admin/kick", s.adminGuard(s.handleKick))
	mux.HandleFunc("/api/admin/settings", s.adminGuard(s.handleSettings))
	mux.HandleFunc("/api/admin/tools/stubs", s.adminGuard(s.handleStubsStatus))
	mux.HandleFunc("/api/admin/tools/stubs/upload", s.adminGuard(s.handleStubsUpload))
	mux.HandleFunc("/api/admin/mikrotik/status", s.adminGuard(s.handleMTStatus))
	mux.HandleFunc("/api/admin/mikrotik/apply", s.adminGuard(s.handleMTApply))

	return logRequests(mux)
}

func logRequests(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		start := time.Now()
		next.ServeHTTP(w, r)
		log.Printf("%s %s %s", r.Method, r.URL.Path, time.Since(start).Round(time.Millisecond))
	})
}
