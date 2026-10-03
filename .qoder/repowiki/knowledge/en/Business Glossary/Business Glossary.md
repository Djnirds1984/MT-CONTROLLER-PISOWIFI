---
kind: business_term
name: Business Glossary
category: business_term
scope:
    - '**'
---

### AIRCOINS NETFI
- Definition：Rebranded product name of the MikroTik hotspot controller panel (formerly CITYCONNECT/JuanFi). All user-facing titles and footers now read 'Powered by AIRCOINS NETFI'. The repository still contains JuanFi asset references (e.g. `assets/css/JuanFi.css`, `juanfi://purchasevoucher` protocol URL) which are functional dependencies and were intentionally left untouched during rebranding.
- Aliases：Aircoins Netfi

### JuanFi
- Definition：Original Piso WiFi captive portal system whose HTML templates, JavaScript hooks, and asset IDs were refactored into this repo. The design was preserved while the visual layout was redesigned; element IDs like `voucherInput`, `connectBtn`, `insertCoinModal`, `totalCoin`, `totalTime`, `expectedCoin`, `codeGenerated`, `vendoSelected` remain byte-exact because `core.js` depends on them.
- Aliases：JuanFi System v4.0

### Piso WiFi
- Definition：Philippine-style coin-operated hotspot model used as the visual reference for the captive portal redesign. The UI mimics a physical 'piso' (coin) machine with INSERT COIN, PAUSE, WIFI RATES, CHARGING STATION, E-LOAD buttons, DISCONNECTED/CONNECTED states, and a countdown timer.
- Aliases：Piso Wi-Fi

### SBC
- Definition：Single-board computer (Orange Pi Zero/PC, Raspberry Pi 3/4, etc.) running Debian/Armbian/Ubuntu that hosts the AIRCOINS NETFI panel — lighttpd on :80 (portal) and :443 (admin), plus php-fpm and SQLite. The SBC sits on the same bridge as the MikroTik hotspot interface.
- Aliases：SBC board、mini PC

### Walled garden
- Definition：MikroTik hotspot configuration (`/ip hotspot walled-garden`) that allows unauthenticated clients to reach the SBC's portal on port 80 before login. Uses `action=accept dst-address=<SBC_IP> dst-port=80 protocol=tcp`. Must not use `action=allow` (only valid for host-name rules).

### IP binding (bypassed)
- Definition：MikroTik hotspot IP binding entry (`type=bypassed`) exempting the SBC's own IP from hotspot interception, preventing redirect loops and ensuring the SBC can always reach the router API. Distinct from the walled-garden rule which lets *clients* reach the SBC.

### Stub
- Definition：Thin redirect page (login.html, alogin.html, error.html, logout.html) uploaded to the MikroTik router's `/hotspot` directory. It carries no portal logic — only a meta-refresh + JS `location.replace()` to the SBC portal URL with `-esc`-encoded RouterOS variables. The heavy assets stay on the SBC.
- Aliases：router stub、redirect stub

### External-mode portal
- Definition：Deployment mode where the captive portal HTML is served by the SBC's lighttpd rather than the router. In this mode `hotspot/js/varbridge.js` substitutes `$(var)` tokens from the query string since RouterOS cannot fill them remotely. The alternative is router-native mode where the router serves the files directly.
- Aliases：external captive portal、SBC-served portal

### REST vs Legacy
- Definition：Two MikroTik API modes selectable per router in the admin panel. REST (`www-ssl`, port 443, JSON, RouterOS v7 only) vs Legacy binary sentence protocol (ports 8728/8728, v6+v7). Auto-detect probes REST first then Legacy. The choice determines which client class (`RestClient` vs `LegacyApiClient`) is instantiated.
- Aliases：API type、REST API、Legacy API

### Session time (uptime-limit)
- Definition：Per-voucher time limit injected into the hotspot user via the `uptime-limit` parameter (format: `10m`, `1h`, `1d`, etc.). Added to the voucher creation chain so MikroTik enforces the limit even when the profile has none. Options range from 10 minutes to 24 hours; leaving it unset defers to the profile.
- Aliases：uptime-limit、session limit

### Hotspot bridge topology
- Definition：Deployment pattern where the SBC is plugged into the same bridge that carries the hotspot (no separate management VLAN). The SBC gets a static IP outside the hotspot DHCP pool and shares the client subnet. This is the default topology documented in DEPLOYMENT.md section 3.
- Aliases：SBC on the hotspot bridge
