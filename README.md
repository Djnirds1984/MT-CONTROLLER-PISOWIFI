# AIRCOINS NETFI — Piso WiFi Server Kit

From-zero server stack for a MikroTik hotspot bound to an SBC panel
(Raspberry Pi, Orange Pi, mini PC — anything running Ubuntu 20.04+ /
Debian 11+ / Armbian). One box serves the captive portal, the admin
panel, and the RADIUS backend the router authenticates against.

```
NodeMCU coin slot ──POST /api/insertCoin.php──┐
                                              ▼
phones ──WiFi──► MikroTik hotspot ──RADIUS──► SBC (this kit)
                    │ login-by=http-pap        ├─ lighttpd  :80   portal /  admin /admin/
                    └─ walled-garden tcp/80 ──►└─ aircoind   127.0.0.1:8088 (Go + SQLite)
```

## Layout (single port 80)

| URL | Serves |
|---|---|
| `http://<sbc>/` | Captive portal (pure HTML/JS/CSS, no PHP) |
| `http://<sbc>/admin/` | Admin SPA — login required (bcrypt + session + CSRF header) |
| `http://<sbc>/api/*` | Proxied to aircoind on 127.0.0.1:8088 |

aircoind keeps two SQLite databases in `/var/lib/aircoins`:
`aircoins.db` (vouchers, settings, vendo rates, admin users) and
`radius.db` — the second is **shared** with FreeRADIUS, which reads
credentials and writes accounting straight into it.

## Quick start

```bash
cd server
sudo bash install.sh              # prompts for admin password, generates secrets
# or unattended:
sudo bash install.sh --yes --admin admin:suPersecret1 --router-ip 10.0.0.1
```

The installer provisions lighttpd (portal + `/admin` alias + `/api` proxy),
FreeRADIUS with the SQLite module, the databases, the `aircoind` systemd
unit, and runs self-tests (portal, admin page, `radtest` Access-Accept).
It is idempotent — re-running never destroys data.

Updating an existing install (static files + binary only, data untouched):

```bash
sudo bash update.sh               # rebuilds aircoind on the box
sudo bash update.sh --prebuilt ./aircoind-linux-arm64   # or push a cross-compiled binary
```

Cross-compiling on a PC (fast, good for small SBCs):

```bash
cd server/go
GOOS=linux GOARCH=arm64 CGO_ENABLED=0 go build -o aircoind-linux-arm64 .
GOOS=linux GOARCH=amd64 CGO_ENABLED=0 go build -o aircoind-linux-amd64 .
GOOS=linux GOARCH=arm  GOARM=7 CGO_ENABLED=0 go build -o aircoind-linux-armv7 .
```

## Binding the MikroTik router

Two ways to reach the correct router settings; both produce the same result:

1. **In-panel configurator** — log in at `/admin/`, open
   **MikroTik setup**, set the router REST user/password in Settings, then
   *Re-check* (shows exactly which setting is wrong) and *Apply
   configuration*. The panel drives the RouterOS v7 REST API and tags every
   object it owns with `comment="aircoins"`, so applying is repeatable.
2. **Manual fallback** — `server/mikrotik/aircoins-hotspot.rsc`. Edit the
   three variables at the top (SBC IP, RADIUS secret, hotspot interface)
   and paste into New Terminal.

What gets configured, and why it must be exactly this:

| Router setting | Value | Reason |
|---|---|---|
| `/radius` | `service=hotspot`, address = SBC IP, ports 1812/1813 | the NAS points at the panel's FreeRADIUS |
| hotspot profile `aircoins` | `use-radius=yes login-by=http-pap,cookie html-directory=flash/hotspot` | CHAP cannot match stored voucher codes and causes "form expired" loops; stubs must stay thin |
| hotspot server | interface → profile `aircoins` | activates hotspot auth on the LAN |
| walled-garden (ip) | `action=accept dst-address=<SBC_IP> protocol=tcp dst-port=80` | clients must reach the portal **before** login |
| ip-binding | `type=bypassed address=<SBC_IP>` | the SBC itself is never redirected |
| `/ip service www` | enabled | the panel talks to the router over REST (HTTP) |

On the SBC side the matching piece is `clients.conf`: the client entry is
keyed by the **router's real source IP** (installer default `10.0.0.1`)
with the same secret. Wrong IP there = every login fails as
`unknown client` in radius.log.

## Admin panel

Dashboard · Vouchers (generate/print/copy/download) · Users (MAC devices +
extended time) · Vendo machines (coin rates per pulse) · Live sessions
(kick) · **MikroTik setup** (the configurator) · Settings (portal name,
URLs, RADIUS secret, hotspot group rates) · Tools (upload the redirect
stubs into the router's `flash/hotspot`).

The NodeMCU firmware contract is preserved unchanged:

```
POST /api/insertCoin.php?mac=AABBCCDDEEFF&coins=N
→ {"status":"true","coins":N,"time_added":"15m","mac":"..."}   (always HTTP 200)
```

## Verification

All gates pass in this repo:

- `gofmt -l` clean; `go vet ./...` clean; `go build ./...` OK
- cross-compiles OK: linux/arm64, linux/amd64, linux/arm (GOARM=7), CGO off
- `bash -n` OK for install.sh and update.sh
- no PHP anywhere under `server/`; firmware endpoint + JSON shape intact

## Repo map

```
firmware/aircoins_netfi/   NodeMCU coin-slot firmware (unchanged contract)
server/
  go/                      aircoind source (single module, modernc.org/sqlite)
  portal/                  captive portal static files
  admin/                   admin SPA static files
  conf/lighttpd/           vhost (token-substituted by install.sh)
  conf/freeradius/         sql module + site + (generated) clients.conf
  db/radius-schema.sql     radius.db schema (mirrors store.go migrations)
  mikrotik/                aircoins-hotspot.rsc manual fallback
  install.sh / update.sh
```
