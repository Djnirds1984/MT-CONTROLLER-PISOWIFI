---
kind: configuration_system
name: AIRCOINS NETFI — PHP Constant-Based Configuration with RouterOS Script Variables
category: configuration_system
scope:
    - '**'
source_files:
    - includes/config.php
    - includes/db.php
    - includes/crypto.php
    - includes/auth.php
    - admin/api/monitor.php
    - hotspot/api.json
    - deploy/mikrotik/hotspot-external-portal.rsc
    - DEPLOYMENT.md
---

## Approach

AIRCOINS NETFI uses a **plain-PHP constant-based configuration system** with no framework, no Composer, and no `.env`/YAML/TOML files. Runtime configuration is loaded by `includes/config.php`, which declares every application constant via `define()` guarded by `defined()` checks so an operator can override values before the file is first included (the comment explicitly calls this out for test harnesses or bootstrap overrides).

A second layer of configuration lives on the MikroTik router as **RouterOS script variables** (`:local sbcIP`, `hsInterface`, `hsNet`, etc.) in `deploy/mikrotik/hotspot-external-portal.rsc`; these are edited before being pasted into WinBox/SSH or imported.

## Key Files

- `includes/config.php` — central PHP constants: database path, libsodium key path, session cookie name, idle timeout, rate-limit max/window.
- `includes/db.php` — reads `AIRCOINS_DB` to open the SQLite database.
- `includes/crypto.php` — reads `AIRCOINS_KEY` to load the 32-byte sodium secretbox key from `/etc/aircoins/secret.key`.
- `includes/auth.php` — uses `AIRCOINS_SESSION_NAME`, `AIRCOINS_IDLE_TIMEOUT`, `AIRCOINS_RATE_MAX`, `AIRCOINS_RATE_WINDOW` for session naming, idle expiry, and login brute-force throttling.
- `admin/api/monitor.php` — references `AIRCOINS_IDLE_TIMEOUT` for monitor cache expiry.
- `hotspot/api.json` — MikroTik template-driven JSON response using RouterOS `$(if …)` / `$(session-timeout-secs)` / `$(remain-bytes-total)` tokens; not a config file but part of the router-side templating layer.
- `deploy/mikrotik/hotspot-external-portal.rsc` — RouterOS script whose top-of-file `:local` variables (`sbcIP`, `hsInterface`, `hsNet`, `hsPoolRange`, `dnsName`, `wanInterface`, etc.) are the single source of truth for network layout.
- `DEPLOYMENT.md` — documents every configuration surface, default values, and deployment paths.

## Architecture & Conventions

1. **Single include point.** Every PHP entry point requires `includes/config.php` first; all other includes depend on its constants. The file is intentionally minimal (44 lines) and safe to require from any entry point.

2. **Override-friendly defaults.** Every constant uses the pattern:
   ```php
   if (!defined('AIRCOINS_X')) {
       define('AIRCOINS_X', 'default');
   }
   ```
   This lets a bootstrap/test file `define()` the same constant earlier to override it. There is no runtime setter.

3. **Constants map directly to filesystem locations.** Defaults pin concrete paths:
   - `AIRCOINS_DB = '/var/lib/aircoins/aircoins.db'`
   - `AIRCOINS_KEY = '/etc/aircoins/secret.key'`
   - Session storage goes under `/var/lib/aircoins/sessions` (created by installer).
   These paths are created and permissioned by `deploy/scripts/install-sbc.sh` (section 6.1 steps 7–8 of DEPLOYMENT.md).

4. **Secrets are externalized from code.** The libsodium key is generated at install time (`base64_encode(sodium_crypto_secretbox_keygen())`) and written to `/etc/aircoins/secret.key` with mode `0400 www-data`. Router passwords are encrypted at rest with that key before being stored in SQLite; they are never rendered back into forms.

5. **No per-environment config files.** There is no `.env`, no `config.local.php`, no YAML/TOML. Site-specific settings (SBC IP, hotspot interface, DHCP range, DNS name, WAN interface) live only in the RouterOS script `hotspot-external-portal.rsc` and in the lighttpd/php-fpm templates under `deploy/lighttpd/` and `deploy/php-fpm/`.

6. **Router-side templating.** The captive portal and router stubs use RouterOS template syntax: `$(variable)` substitution and `$(if logged-in == 'yes')…$(endif)` blocks (see `hotspot/api.json`, `router-stubs/*.html`). When served from the SBC, `hotspot/js/varbridge.js` fills these placeholders client-side from the query string.

7. **Admin panel runtime state is in SQLite, not config.** Routers, users, vouchers, sessions, and audit logs are persisted in the SQLite database opened via `AIRCOINS_DB`; there is no admin-settings table backed by a config file.

## Conventions & Constraints

- **All application constants are prefixed `AIRCOINS_`** and declared exclusively in `includes/config.php` (verified by grep across the codebase — only `config.php` defines them; consumers reference them).
- **Defaults are hard-coded in `config.php`**, not in environment variables or separate files. Changing a default means editing `includes/config.php`.
- **Overrides must be done before `includes/config.php` is included** (enforced by the `defined()` guard); there is no API to mutate configuration after loading.
- **The installation script enforces the expected filesystem layout**: `/var/lib/aircoins/`, `/etc/aircoins/`, `/var/www/aircoins/portal`, `/var/www/aircoins/app`, `/run/php/php*-fpm-aircoins.sock`, `/etc/lighttpd/certs/aircoins.pem` — deviating breaks the defaults in `config.php` and the lighttpd/php-fpm configs.
- **RouterOS script variables are the authoritative site configuration** for the router side; DEPLOYMENT.md section 7.1 says "Edit the site variables at the top first" and lists each `:local` variable with its meaning.
- **MikroTik template tags (`$(…)`, `$(if …)…$(endif)`) must remain byte-exact** between `router-stubs/` and `hotspot/` copies because `varbridge.js` relies on exact token names when bridging query params to DOM elements (documented in DEPLOYMENT.md section 10).
- **No framework or build step** is used for configuration — stated explicitly in both `includes/config.php` header and DEPLOYMENT.md design goals.