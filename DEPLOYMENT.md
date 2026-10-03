# AIRCOINS NETFI — Deployment Guide

Complete guide for deploying the **AIRCOINS NETFI** controller on a single-board
computer (SBC) behind a MikroTik hotspot. It covers the SBC install, the MikroTik
redirect configuration (the core of the setup), the admin panel, portal
customization, troubleshooting, and security.

---

## 1. Overview

AIRCOINS NETFI is a coin/voucher-style hotspot controller split across **two
hosts**:

| Component | Runs on | Purpose |
|---|---|---|
| **Captive portal** | SBC, port **80** | The customer-facing login/status pages (`hotspot/`, pure HTML + JuanFi assets). |
| **Portal session API** | SBC, port **80** (`/api/session.php`) | Gives the status page live session data by querying routers through the API. |
| **Admin panel** | SBC, port **443** (self-signed TLS) | PHP 8 UI to manage routers, hotspot users/vouchers, kick sessions, monitor live traffic. |
| **Hotspot + API** | MikroTik router | Serves thin redirect **stubs**, authenticates clients (HTTP-PAP), exposes REST/Legacy API for the admin panel. |

All panel→router traffic uses the MikroTik **API** (never SSH/screen-scraping).
Storage is **SQLite**; router passwords are encrypted **at rest** with libsodium;
admin passwords are hashed with **Argon2id**.

Design goals: no framework, no Composer, no build step, low RAM/flash footprint
suitable for an Orange Pi / Raspberry Pi running Armbian, Debian bookworm, or
Ubuntu 24.04.

---

## 2. Architecture

```
        +------------------+        HTTP-PAP POST (voucher)        +--------------------+
        |   Hotspot client | ------------------------------------> |   MikroTik router   |
        |  (phone / laptop)|                                       |  RouterOS v6 / v7   |
        +--------+---------+                                       +---------+----------+
                 |                                                           |
   (1) associate | DHCP                                        serves stubs  | (2) /hotspot/login.html
                 v                                                           v   (meta-refresh)
        +------------------+   (3) GET http://<SBC_IP>/login.html   +--------------------+
        |       SBC        | <------------------------------------- | router-stubs/*.html|
        | lighttpd :80     |      walled-garden allows this         | (on the router)    |
        |  portal (hotspot)|                                        +--------------------+
        |  /api/session.php|
        +--------+---------+
                 |
                 | (5) status page polls /api/session.php?mac=...
                 | (4) login form POSTs voucher back to router (HTTP-PAP) -> online
                 v
        +------------------+   admin traffic (management LAN)   +--------------------+
        |       SBC        | <--------------------------------- |  Operator browser  |
        | lighttpd :443    |   https://<SBC_IP>/ (admin panel)  +--------------------+
        |  admin/ (PHP-FPM)|
        +--------+---------+
                 |
                 | (6) REST (443/www-ssl) OR Legacy API (8728/8729)
                 v
        +--------------------+
        |   MikroTik router   |   hotspot users, active sessions, resources, kick
        +--------------------+
```

* **(1)** Client associates; MikroTik DHCP hands out an address from the hotspot pool.
* **(2)** Any HTTP request is intercepted; the router serves its thin `login.html` **stub**.
* **(3)** The stub meta-refreshes the browser to the SBC portal (`http://<SBC_IP>/login.html?...`), passing `mac/ip/dst/login/logout/user/err`. The **walled garden** lets this reach the SBC before authentication.
* **(4)** The portal login form POSTs the voucher **back to the router** (`login` URL) using **HTTP-PAP**. On success the router serves `alogin.html` (stub) → redirects to the SBC **status** page.
* **(5)** The status page polls `/api/session.php?mac=...` on the SBC; that PHP endpoint asks the routers (via API) whether the MAC has an active session.
* **(6)** The admin panel (port 443) manages routers/users/sessions over the chosen API.

---

## 3. End-to-end redirect flow (numbered)

1. **Association + DHCP** — the client joins the hotspot interface; MikroTik's DHCP server (`.rsc` section 2) leases an address from `hs-pool` (SBC IP is kept outside the pool).
2. **First HTTP hit is intercepted** — the hotspot redirects the browser to the router's own `/hotspot/login.html`.
3. **Router serves the stub** — `router-stubs/login.html` contains only a `<meta http-equiv="refresh">` + a JS `location.replace()` to `http://<SBC_IP>/login.html?mac=$(mac-esc)&ip=$(ip-esc)&dst=$(link-orig-esc)&login=$(link-login-only-esc)&logout=$(link-logout-esc)&user=$(username-esc)&err=$(error-esc)`. RouterOS substitutes the `$(...)` tokens server-side before the browser sees them.
4. **Browser loads the SBC portal** — the walled-garden rule (`action=accept dst-address=<SBC_IP> dst-port=80`) lets this through even though the client is not yet authenticated. `hotspot/js/varbridge.js` reads `location.search`, fills the `$(...)` placeholders into the DOM, and sets the login form's `action` to the `login` URL.
5. **User submits the voucher** — in external mode the portal POSTs `username=<voucher>` / `password=<voucher>` (HTTP-PAP, plaintext) to the router's login URL as a real form navigation (no CORS/XHR).
6. **Router authenticates** — on success it serves `/hotspot/alogin.html` (stub), which redirects the browser to the SBC **status** page (`dst`), with `$(link-redirect)` as fallback.
7. **Status page goes live** — `hotspot/status.html` polls `http://<SBC_IP>/api/session.php?mac=<mac>` every ~10s. The endpoint queries each enabled router via its API and returns `{connected,user,uptime,bytes_in,bytes_out,time_left}`.
8. **Logout / error** — `logout.html` and `error.html` stubs redirect back to the SBC portal with the appropriate params (e.g. `err=$(error-esc)`).

---

## 4. Prerequisites

### Hardware
* An SBC with ≥ 512 MB RAM and a microSD/eMMC (Orange Pi Zero/PC, Raspberry Pi 3/4, etc.).
* Wired or wireless Ethernet on the **same L3 subnet** as the hotspot clients and the router.
* The router's hotspot interface (`bridge-hotspot` in the example) carries clients **and** the SBC.

### Operating system
* Debian 12 (bookworm) → PHP **8.2**, Ubuntu 24.04 (noble) → PHP **8.3**, or Armbian based on either.
* Root/sudo access. `systemd`.

### Network / addressing (defaults used in this guide)
| Role | Default | Notes |
|---|---|---|
| MikroTik router (gateway) | `192.168.88.1` | `hsAddress 192.168.88.1/24`, `gwIP`. |
| Hotspot client network | `192.168.88.0/24` | `hsNet`. |
| DHCP lease range | `192.168.88.100–254` | `hsPoolRange`. |
| **SBC panel IP** | **`192.168.88.10`** | Static; **must be outside** the DHCP range. Referenced everywhere (`sbcIP`). |
| Portal hostname | `hotspot.aircoins.local` | Static DNS → SBC (`dnsName`). |
| WAN uplink | `ether1` | `wanInterface` for masquerade. |

> Give the SBC a **static IP** (or a DHCP reservation) matching `sbcIP`. If the SBC IP changes, you must update the walled-garden rule, the ip-binding, the static DNS entry, **and** the two occurrences of the IP inside each `router-stubs/*.html`.

### Required PHP extensions
`fpm`, `cli`, `sqlite3`, `curl`, `mbstring`, **`sodium`** (all installed by the script).

---

## 5. SBC installation

### 5.1 One-shot installer (recommended)

Copy the repository to the SBC (scp/git), then run:

```bash
cd /path/to/MT-CONTROLLER-PISOWIFI
sudo bash deploy/scripts/install-sbc.sh            # source repo = ../.. by default
# or point at a repo explicitly:
sudo bash deploy/scripts/install-sbc.sh /path/to/MT-CONTROLLER-PISOWIFI
```

The installer is **idempotent** and does, in order:

1. Root check; detects architecture (`dpkg --print-architecture`), OS (`/etc/os-release`) and PHP version.
2. `apt-get update`; installs `lighttpd php<FPMVER>-{fpm,cli,sqlite3,curl,mbstring,sodium} openssl ufw ca-certificates` (falls back to versionless metapackages if the versioned ones are unavailable).
3. Disables `apache2`/`nginx` if present (they would grab port 80).
4. Creates the layout under `/var/www/aircoins` and copies `hotspot/`, `admin/`, `includes/`, `api/`; `chown -R www-data`.
5. Installs `aircoins.conf` as the **main** lighttpd config (stock one backed up to `lighttpd.conf.aircoins-bak`), installs the php-fpm pool, creates the data/session dirs, restarts `php<FPMVER>-fpm`.
6. Generates a self-signed certificate → `/etc/lighttpd/certs/aircoins.pem` (`chmod 600`).
7. Creates `/etc/aircoins/secret.key` (base64 32-byte sodium key, `0400 www-data`) and `/var/lib/aircoins` (`0750 www-data`).
8. Initializes the SQLite schema and **prompts for the first admin username/password** (stored as an Argon2id hash via `includes/auth.php aircoins_hash()`).
9. `ufw allow 80/tcp,443/tcp` and reloads ufw **only if already active** (never auto-enables, to avoid locking out SSH).
10. `lighttpd -t -f /etc/lighttpd/lighttpd.conf`, then `systemctl enable --now lighttpd`, and prints a verification checklist.

### 5.2 Manual fallback (if you can't run the script)

```bash
# 1) packages (adjust 8.2 -> your PHP version)
sudo apt-get update
sudo apt-get install -y lighttpd php8.2-fpm php8.2-cli php8.2-sqlite3 \
    php8.2-curl php8.2-mbstring php8.2-sodium openssl ufw ca-certificates

# 2) layout + code
sudo mkdir -p /var/www/aircoins/portal /var/www/aircoins/app
sudo cp -a hotspot/.  /var/www/aircoins/portal/
sudo cp -a admin/.    /var/www/aircoins/app/admin/
sudo cp -a includes/. /var/www/aircoins/app/includes/
sudo cp -a api/.      /var/www/aircoins/app/api/
sudo chown -R www-data:www-data /var/www/aircoins

# 3) data + key dirs
sudo mkdir -p /var/lib/aircoins/sessions /etc/aircoins /var/cache/lighttpd/uploads
sudo chown -R www-data:www-data /var/lib/aircoins /etc/aircoins
sudo chmod 750 /var/lib/aircoins && sudo chmod 700 /var/lib/aircoins/sessions /etc/aircoins
sudo -u www-data php -r 'echo base64_encode(sodium_crypto_secretbox_keygen());' \
    | sudo tee /etc/aircoins/secret.key >/dev/null
sudo chown www-data:www-data /etc/aircoins/secret.key && sudo chmod 400 /etc/aircoins/secret.key

# 4) TLS cert
sudo mkdir -p /etc/lighttpd/certs
sudo openssl req -x509 -nodes -days 3650 -newkey rsa:2048 \
    -keyout /tmp/a.key -out /tmp/a.crt -subj "/O=AIRCOINS NETFI/CN=$(hostname -f)"
sudo sh -c 'cat /tmp/a.crt /tmp/a.key > /etc/lighttpd/certs/aircoins.pem'
sudo chmod 600 /etc/lighttpd/certs/aircoins.pem

# 5) configs (replace 8.2 in the socket/pool paths with your PHP version)
sudo sed -e 's#@@PORTAL_DOCROOT@@#/var/www/aircoins/portal#g' \
         -e 's#@@ADMIN_DOCROOT@@#/var/www/aircoins/app/admin#g' \
         -e 's#@@API_DIR@@#/var/www/aircoins/app/api#g' \
         -e 's#@@FPM_SOCKET@@#/run/php/php8.2-fpm-aircoins.sock#g' \
         deploy/lighttpd/aircoins.conf | sudo tee /etc/lighttpd/lighttpd.conf >/dev/null
sudo sed -e 's#@@FPMVER@@#8.2#g' deploy/php-fpm/aircoins-pool.conf \
    | sudo tee /etc/php/8.2/fpm/pool.d/aircoins.conf >/dev/null

# 6) start
sudo systemctl restart php8.2-fpm
sudo lighttpd -t -f /etc/lighttpd/lighttpd.conf && sudo systemctl enable --now lighttpd
```

### 5.3 How lighttpd + php-fpm fit together

* **lighttpd** is the only listener on ports 80 and 443. It serves the static portal directly and forwards `.php` requests to **php-fpm** over a UNIX socket (`fastcgi.server` in `aircoins.conf`).
* **php-fpm** runs a dedicated `[aircoins]` pool (`pm = ondemand`, `max_children = 5`) on `/run/php/php<FPMVER>-fpm-aircoins.sock`. `ondemand` means workers spawn only on traffic — near-zero idle RAM.
* The `/api/` alias (port 80 only) maps `/api/session.php` to `app/api/session.php`, which lives **outside** the portal docroot; `check-local = disable` + `broken-scriptfilename = enable` make that work.
* On port **443** the docroot is `app/admin/`, so admin relative URLs (`assets/…`, `api/monitor.php`, `routers.php`) resolve correctly and `/api/monitor.php` maps to `admin/api/monitor.php`.

### 5.4 Verification checklist

```bash
curl -I  http://localhost/                                   # 200, login.html
curl -s  "http://localhost/api/session.php?mac=00:00:00:00:00:00"   # {"connected":false}
curl -kI https://localhost/                                   # 200/302, admin login.php
ss -tlnp | grep -E ':(80|443)\b'                             # lighttpd listening
ls -l /run/php/php*-fpm-aircoins.sock                         # php-fpm socket present
systemctl status lighttpd php*-fpm --no-pager
```

---

## 6. MikroTik redirect configuration (the core step)

Two things must happen on the router: **(a)** apply the hotspot/walled-garden/service
config, and **(b)** upload the thin redirect **stubs**.

### 6.1 Paste-ready script — `deploy/mikrotik/hotspot-external-portal.rsc`

The repo ships a complete, commented RouterOS script. **Edit the site variables at
the top first** (section 0), then paste it into WinBox **New Terminal** (or SSH),
or upload and run:

```
/import file-name=hotspot-external-portal.rsc
```

Variables to edit (top of the file):

| Variable | Default | Meaning |
|---|---|---|
| `sbcIP` | `192.168.88.10` | Your SBC panel IP (appears in walled-garden, ip-binding, static DNS). |
| `hsInterface` | `bridge-hotspot` | Interface carrying clients **and** the SBC. |
| `hsNet` | `192.168.88.0/24` | Hotspot client network. |
| `hsAddress` / `gwIP` | `192.168.88.1/24` / `192.168.88.1` | Router gateway address. |
| `hsPoolRange` | `192.168.88.100-192.168.88.254` | DHCP range (**SBC IP must be outside it**). |
| `dnsName` | `hotspot.aircoins.local` | Portal hostname → SBC (static DNS). |
| `wanInterface` | `ether1` | Uplink for internet masquerade. |

What the script configures (section by section):

1. **Device-mode gate (v7 only)** — `/system device-mode set hotspot=yes`. Left commented; **v7 users must uncomment and run it once** (v6 has no device-mode).
2. **Addressing** — router IP, DHCP pool, DHCP server + network, `allow-remote-requests=yes`.
3. **Hotspot profile** — `login-by=http-pap,cookie`, `dns-name`, `hotspot-address`, `html-directory=hotspot`, `use-radius=no`.
4. **Hotspot server** — bound to `hsInterface` with the pool + profile.
5. **Walled garden** — `/ip hotspot walled-garden ip add action=accept dst-address=<SBC_IP> dst-port=80 protocol=tcp` so unauthenticated clients can load the portal.
6. **IP binding** — `/ip hotspot ip-binding add address=<SBC_IP> type=bypassed` so the SBC is never challenged/redirected (prevents redirect loops and lets it reach the router API).
7. **Services** — `/ip service enable www-ssl` (REST) and/or `/ip service enable api`/`api-ssl` (Legacy). Enable the one your admin panel uses.
8. **Static DNS** — `/ip dns static add name=<dnsName> address=<SBC_IP>`.
9. **NAT** — `srcnat` masquerade out `wanInterface`.
10. **Final step** — upload the stubs (below).

### 6.2 WinBox equivalents (if you prefer clicking)

| Task | WinBox path |
|---|---|
| Device-mode (v7) | **System → Device Mode** → enable *hotspot* |
| Gateway IP | **IP → Addresses** → add `192.168.88.1/24` on `bridge-hotspot` |
| DHCP pool + server | **IP → Pool**, **IP → DHCP Server** (+ **Network** tab: gateway/DNS) |
| Hotspot profile | **IP → Hotspot → Server Profiles** → add, *Login By* = `http-pap,cookie`, *DNS Name*, *Hotspot Address* |
| Hotspot server | **IP → Hotspot → Servers** → add on `bridge-hotspot` with pool + profile |
| Walled garden | **IP → Hotspot → Walled Garden → IP** tab → add `action=accept`, `dst-address=192.168.88.10`, `dst-port=80`, `protocol=tcp` |
| IP binding | **IP → Hotspot → IP Bindings** → add `address=192.168.88.10`, `type=bypassed` |
| Services | **IP → Services** → enable `www-ssl` (443) and/or `api` (8728) / `api-ssl` (8729) |
| Static DNS | **IP → DNS → Static** → add `name=hotspot.aircoins.local`, `address=192.168.88.10` |
| NAT | **IP → Firewall → NAT** → add `chain=srcnat`, `out-interface=ether1`, `action=masquerade` |
| Upload stubs | **Files** → drag & drop into `/hotspot` |

### 6.3 Upload the router stubs (which file replaces which)

The router keeps **only** four thin pages; everything else (portal, PHP, assets)
lives on the SBC. Before uploading, **edit each stub and replace `192.168.88.10`
with your real `sbcIP`** (in `login.html` the IP appears **twice**: the `<meta
refresh>` URL and the JS `location.replace()` URL — keep them identical).

| Repo file | Upload to router | Purpose |
|---|---|---|
| `router-stubs/login.html` | `/hotspot/login.html` | Redirects to the SBC portal with client params. **Keep** `<!-- IAMNOTLOGINSTRINGPLEASEDONTREMOVE -->` — RouterOS uses it to recognize the login page. |
| `router-stubs/alogin.html` | `/hotspot/alogin.html` | Post-login redirect to the SBC status page (`dst`, fallback `$(link-redirect)`). |
| `router-stubs/error.html` | `/hotspot/error.html` | Redirects to the SBC login with `err=$(error-esc)`. |
| `router-stubs/logout.html` | `/hotspot/logout.html` | Redirects to the SBC login with `mac`/`ip` (disconnected state). |

> The heavy `hotspot/` assets (`css/`, `js/`, `img/`, `status.html`, JuanFi `assets/`) are **NOT** uploaded to the router — they are served from the SBC.

### 6.4 Walled garden & IP binding — why both

* **Walled garden (`action=accept`)** allows a *not-yet-authenticated* client to open `http://<SBC_IP>:80` so the login page itself can load. Use `action=accept` — `allow` is only valid on host-name (non-`ip`) walled-garden rules.
* **IP binding (`type=bypassed`)** exempts the **SBC's own** traffic from hotspot interception, so the SBC is always reachable and can always talk to the router API. Without it you can get redirect loops between the router stub and the SBC.
* If admins browse the panel **from the hotspot side**, also walled-garden `dst-port=443` (commented in the `.rsc`). Normally admin traffic comes from the management network and doesn't need it.

### 6.5 Profile & services per API type

* **Profile** must use `login-by=http-pap,cookie`. CHAP is impractical for an external page (the router generates the challenge per request), so the portal submits the voucher in **plaintext PAP** over the isolated hotspot LAN. `cookie` keeps the client logged in for the session.
* **Services** — enable only what the admin panel uses for that router: `www-ssl` for **REST** (v7, needs a certificate; RouterOS auto-generates a self-signed one), `api`/`api-ssl` for **Legacy** (usually already enabled). See section 7.

---


## 7. REST vs Legacy API — which is which

The admin panel can talk to a router over **two** different MikroTik APIs. You
choose per-router when adding it (radio: *REST API* / *Legacy API*). The
*Auto-detect* button probes REST first (443), then Legacy (8728).

| Property | **REST API** | **Legacy (binary) API** |
|---|---|---|
| RouterOS versions | **v7 only** | **v6 and v7** |
| TCP port | **443** (`www-ssl`) | **8728** (`api`) plaintext / **8729** (`api-ssl`) TLS |
| Service name | `www-ssl` | `api` / `api-ssl` |
| Protocol | HTTPS, JSON bodies (all values are **strings**) | Binary *sentence* protocol (length-prefixed words) |
| Auth | HTTP **Basic** | `/login` sentence — plain (post-6.43) or challenge-response `00+md5(00+pw+challenge)` (pre-6.43) |
| Verbs | `GET`, `PUT`=add, `PATCH`=set, `DELETE`=remove, `POST`=commands | `/ip/hotspot/user/add`, `.../print`, `.../remove`, `/ip/hotspot/active/print`, … |
| Enable command | `/ip service enable www-ssl` | `/ip service enable api` (often already on) |
| Needs a certificate | Yes (self-signed OK; client disables peer verify) | Only for `api-ssl` |
| Item addressing | `.../<path>=.id=*HEX` (no percent-encoding) | `.id` word in sentences |
| **Choose it when…** | Router is v7 and you can open 443 | Router is v6, or 443 is unavailable, or you want the widest compatibility |

> Both clients live in `includes/RouterOS/`: `RestClient.php` (cURL, Basic auth, `Content-Type: application/json`, 10s timeout, peer-verify off) and `LegacyApiClient.php` (`stream_socket_client` to `tcp://:8728` or `ssl://:8729`, full 1/2/3/4/5-byte word length encode/decode, dual-mode login, `!re/!done/!trap/!fatal` handling). `RouterFactory.php` picks the client from `routers.api_type`.

---

## 8. Admin panel — first login & Add Router

Open **`https://<SBC_IP>/`** (accept the self-signed-certificate warning).

### 8.1 First login
Use the username/password you created during install (step 8 of the installer). If you skipped it, create one with the `php -r` snippet the installer printed. Login is **rate-limited** (5 failures / 300s per IP) and every attempt is audited. Sessions idle out after 15 minutes.

### 8.2 Add Router walkthrough (`routers.php` → *Add router*)

| Field | What to enter |
|---|---|
| **Name** | Friendly label (e.g. `Piso-Shop-1`). |
| **Host** | Router IP reachable from the SBC (e.g. `192.168.88.1`). |
| **API type** | Radio: **REST API — RouterOS v7** or **Legacy API — v6 & v7**. |
| **Port** | Auto-filled by API type (REST `443`, Legacy `8728`/`8729`); editable. |
| **Username** | RouterOS admin user with hotspot privileges. |
| **Password** | RouterOS password — encrypted with libsodium before storage; never rendered back into the form (an edit leaves it blank to keep the stored secret). |
| **TLS verify** | Toggle. Leave **off** for self-signed router certs. |

* **Auto-detect** — probes REST (443) then Legacy (8728) with the entered host/user/password and pre-selects the working type/port.
* **Test connection** — validates credentials and reports identity/version without saving.
* **Save** — the password is encrypted (`aircoins_encrypt()`), the row is stored, and the action is written to the audit log.

### 8.3 Dashboard (`index.php`)
One live card per **enabled** router: identity, RouterOS version, CPU load, memory used, uptime, active-session count, and per-interface traffic rates. `assets/admin.js` polls `api/monitor.php` every 10s; a monitor failure flips that card to an error state without breaking the page.

### 8.4 Hotspot & vouchers (`hotspot.php`)
Pick a router, then use the tabs:
* **Users** — list / add / delete hotspot users.
* **Vouchers** — bulk generator: *prefix + count + profile* → creates hotspot users via the API and records the batch.
* **Active Sessions** — list live sessions and **kick** individual clients.

All POSTs are CSRF-protected; all output is escaped; all queries are prepared statements; router CRUD / kick / voucher creation are audited.

---

## 9. Portal customization

The portal is the `hotspot/` tree (deployed to `/var/www/aircoins/portal`). Edit it in the repo, then re-sync with `deploy/scripts/update-portal.sh` (no service restart needed — lighttpd serves static files from disk).

* **Banner / branding image** — replace `hotspot/assets/MainPic.PNG` (and `hotspot/assets/img/*.png` icons). Keep the same filenames so existing HTML/JS references stay valid.
* **Rates / promo modal** — the promo/rates content is rendered into the JuanFi modals (`promoRatesModal`, etc.) driven by `hotspot/assets/js/core.js` + `config.js`. Edit the text/HTML inside those modal containers; **do not rename the element IDs** the controller script relies on (`voucherInput`, `connectBtn`, `promoRateBtn`, `insertCoinModal`, `totalCoin`, `totalTime`, `expectedCoin`, `codeGenerated`, `vendoSelected`, …).
* **Branding / colors** — layer custom CSS with namespaced classes (e.g. `.piso-*` / AIRCOINS classes) over the Bootstrap assets rather than replacing them; the modals depend on Bootstrap JS.
* **Sounds / animations** — `coin-received.mp3`, `insertcoinbg.mp3`, `insert-coin-animation.gif`.
* **MikroTik template tags** — any `$(variable)` / `$(if …)…$(endif)` in `hotspot/*.html` and `router-stubs/*.html` are substituted **router-side**; when served from the SBC they are filled client-side by `js/varbridge.js` from the query string. Keep them byte-exact.

After editing, re-sync:

```bash
sudo bash deploy/scripts/update-portal.sh /path/to/MT-CONTROLLER-PISOWIFI
```

`update-portal.sh` reloads php-fpm only if `includes/`, `admin/` or `api/` changed (portal-only edits need nothing).

---

## 10. Troubleshooting

**Port 80 already in use (apache2 / nginx / pi-hole / dnsmasq).**
`sudo ss -tlnp | grep ':80'` to find the culprit. The installer disables `apache2`/`nginx`. For Pi-hole/pihole-FTL or another lighttpd, stop/disable it or move AIRCOINS to a different box. `sudo systemctl disable --now apache2 nginx`.

**Armbian: config changes seem to vanish / nftables blocks ports.**
Some Armbian images use an overlay or `armbian-config` network manager that can rewrite `/etc/network/interfaces`; make the SBC IP static via Netplan/`/etc/netplan/*.yaml` (Ubuntu-based) or `armbian-config → Network`. Armbian may ship `nftables`/`firewalld` instead of `ufw` — open 80/443 there too (`sudo nft add rule inet filter input tcp dport {80,443} accept`). Ensure the static IP stays **outside** the hotspot DHCP range.

**PAP plaintext note.** The portal submits the voucher in cleartext (HTTP-PAP) over the isolated hotspot LAN — this is by design (CHAP is impractical for an external page). Keep the hotspot on its own VLAN/SSID; the admin panel (router credentials) is protected by TLS on 443.

**The `-esc` rule.** In `router-stubs/*.html`, always use the `-esc` variants inside URLs/query strings (`$(mac-esc)`, `$(ip-esc)`, `$(link-orig-esc)`, `$(link-login-only-esc)`, `$(link-logout-esc)`, `$(username-esc)`, `$(error-esc)`) so RouterOS URL-encodes special characters. Using the non-`-esc` form in a query string breaks redirect chains with `&`/spaces.

**REST errors:**
* `401 Unauthorized` — wrong username/password, or Basic auth not accepted; check `/ip service enable www-ssl` and the user's group/permissions.
* `415 Unsupported Media Type` — request lacked `Content-Type: application/json` (the client sets it; a proxy may strip it).
* `404 Not Found` — wrong path/addressing; REST uses `=remX`/`.id=*HEX` style paths and no percent-encoding of `.id`.
* `400 Bad Request` — malformed JSON or wrong verb (`PUT`=add, `PATCH`=set, `DELETE`=remove). Response body carries `{"error","message","detail"}`.
* Connection refused — `www-ssl` disabled, firewall, or wrong port (443).

**Legacy `!trap` messages.** A `!trap` sentence carries a `message` word explaining the failure (e.g. `no such item`, `invalid username or password`, `already have …`). The client surfaces that message; `!fatal` closes the connection (usually auth failure or protocol error). Verify `/ip service enable api` and port 8728/8729.

**`session.php` returns `connected:false` even when online.**
* The status page passes the wrong/blank `mac` — check `?mac=` in the URL and `js/varbridge.js`.
* No router is **enabled** in the admin panel, or the router is unreachable from the SBC (test with *Test connection*).
* `findActiveByMac()` found no active session for that MAC (client logged in under a different MAC/interface).
* The `/api/` alias didn't apply (must be port 80/http) — `curl -s "http://<SBC_IP>/api/session.php?mac=AA:BB:CC:DD:EE:FF"`.

**SD-card wear.** Access logging is disabled (`mod_accesslog` not loaded) and SQLite runs in WAL + `synchronous=NORMAL`. Consider `log2ram` on Armbian/Raspberry Pi OS and a quality/endurance microSD. `monitor_samples` is pruned to 24h automatically.

**Time sync.** Argon2id, TLS and rate-limit windows depend on correct time. Ensure `systemd-timesyncd`/`chrony` is active: `timedatectl status`; `sudo timedatectl set-ntp true`. A wrong clock causes spurious logouts and TLS warnings.

**lighttpd won't start.** `sudo lighttpd -t -f /etc/lighttpd/lighttpd.conf` to validate, then `journalctl -u lighttpd -n 40`. Common causes: missing `/etc/lighttpd/certs/aircoins.pem`, or the php-fpm socket path in `aircoins.conf` not matching the pool (both are `<FPMVER>`-substituted by the installer).

---

## 11. File structure

### Repository (source)
```
MT-CONTROLLER-PISOWIFI/
├── hotspot/                     # SBC-served captive portal (pure HTML)
│   ├── login.html  status.html  alogin.html  error.html  logout.html ...
│   ├── js/varbridge.js          # query-param bridge + external-mode detection
│   ├── assets/{css,js,img}/...  # Bootstrap + JuanFi controller assets
│   └── md5.js
├── router-stubs/                # upload to MikroTik /hotspot (replace originals)
│   ├── login.html  alogin.html  error.html  logout.html
├── includes/                    # shared PHP core (NO framework, NO Composer)
│   ├── config.php db.php auth.php csrf.php crypto.php helpers.php layout.php
│   └── RouterOS/
│       ├── RouterClientInterface.php RestClient.php LegacyApiClient.php RouterFactory.php
├── admin/                       # admin panel UI + endpoints
│   ├── login.php logout.php index.php routers.php hotspot.php
│   ├── api/monitor.php
│   └── assets/{admin.css,admin.js}
├── api/session.php              # portal-facing session JSON (MAC lookup)
├── deploy/
│   ├── lighttpd/aircoins.conf       # port 80 portal + 443 admin + fastcgi + 404 fallback
│   ├── php-fpm/aircoins-pool.conf   # ondemand pool for the SBC
│   ├── mikrotik/hotspot-external-portal.rsc   # full RouterOS setup
│   └── scripts/install-sbc.sh  update-portal.sh
└── DEPLOYMENT.md                # this guide
```

### Deployed layout (SBC)
```
/var/www/aircoins/
├── portal/                      # <- hotspot/    (port 80 docroot)
└── app/
    ├── admin/                   # <- admin/      (port 443 docroot)
    │   └── api/monitor.php      #    /api/monitor.php  (inside docroot)
    ├── includes/                # <- includes/   (sibling of admin/ and api/)
    └── api/                     # <- api/        (aliased at /api/ on port 80)
        └── session.php          #    requires ../includes/...

/etc/lighttpd/lighttpd.conf            # installed from deploy/lighttpd/aircoins.conf
/etc/lighttpd/certs/aircoins.pem       # self-signed TLS (600)
/etc/php/<FPMVER>/fpm/pool.d/aircoins.conf
/run/php/php<FPMVER>-fpm-aircoins.sock # dedicated php-fpm socket
/etc/aircoins/secret.key               # sodium key (0400 www-data)
/var/lib/aircoins/aircoins.db          # SQLite (+ /sessions for PHP sessions)
/var/log/php-fpm-aircoins.log          # PHP error log
```

> **Why this layout:** `admin/*.php` do `require_once __DIR__.'/../includes/...'`, `admin/api/monitor.php` does `'./../../includes/...'`, and `api/session.php` does `'./../includes/...'`. Placing `admin/`, `includes/`, `api/` as **siblings under `app/`** satisfies every relative require, while the docroots (`portal/` on :80, `app/admin/` on :443) satisfy the web URL mapping.

---

## 12. Security notes

* **Admin passwords — Argon2id.** Stored via `aircoins_hash()` (Argon2id, bcrypt fallback). Never stored or logged in plaintext; hashes auto-upgrade on login (`aircoins_needs_rehash()`).
* **Router passwords — sodium at rest.** Encrypted with XSalsa20-Poly1305 (`sodium_crypto_secretbox`) using a 32-byte key at `/etc/aircoins/secret.key` (`0400 www-data`, outside the web root). Decrypted only in memory for a single API call. **Back up this key** — losing it makes stored router passwords unrecoverable; rotating it invalidates them.
* **CSRF.** Every state-changing admin POST carries a per-session token verified with `hash_equals` (`includes/csrf.php`).
* **Rate limiting.** Admin login is throttled to 5 failures / 300s per IP (`login_attempts`), with session-id regeneration on success and a 15-minute idle timeout. Cookie is `HttpOnly`, `SameSite=Strict`, and `Secure` over TLS.
* **Self-signed TLS.** The admin site uses a self-signed certificate (`aircoins.pem`); browsers will warn — that is expected. Replace it with a CA/Let's-Encrypt cert if the panel is reachable by hostname on the internet. Router REST/Legacy clients disable peer verification to tolerate router self-signed certs on an isolated LAN.
* **Portal session API.** `/api/session.php` is intentionally unauthenticated but **read-only**: it only reports whether a given MAC has an active session (non-sensitive, per-caller). It opens CORS (`*`) for that single endpoint and never leaks stack traces.
* **Port-80 admin variant — WARNING.** `aircoins.conf` includes a commented block to serve the admin under `http://<SBC_IP>/admin/` with a private-range IP allowlist. Over plain HTTP the admin login **and router credentials you type** travel in cleartext — use only on a fully trusted, isolated management network. The TLS site on port 443 is the recommended default.
* **Firewall.** Only ports 80 (portal) and 443 (admin) need to be reachable by clients; keep the router's API ports (443/8728/8729) reachable **only** from the SBC (management network), not from hotspot clients.
* **Least privilege.** Everything runs as `www-data`; the PHP pool uses `open_basedir` limited to `/var/www/aircoins:/var/lib/aircoins:/etc/aircoins:/tmp:/var/cache/lighttpd:/var/log/php-fpm-aircoins.log`, and `display_errors` is off.

---

*End of AIRCOINS NETFI deployment guide.*
