---
kind: logging_system
name: PHP-FPM error_log sink with no application-level logger
category: logging_system
scope:
    - '**'
source_files:
    - deploy/php-fpm/aircoins-pool.conf
    - deploy/lighttpd/aircoins.conf
    - DEPLOYMENT.md
---

## What system/approach is used

The AIRCOINS MikroTik Hotspot Controller has **no application-level logging framework**. There is no `log/` or `logging/` directory, no PSR-3 logger, no custom logger class, and no structured log fields. The only logging mechanism is PHP's built-in `error_log`, routed by PHP-FPM to a single file.

## Key files and packages

- `deploy/php-fpm/aircoins-pool.conf` — defines the runtime sink:
  - `php_admin_flag[log_errors] = On`
  - `php_admin_value[error_log] = /var/log/php-fpm-aircoins.log`
  - `php_admin_flag[display_errors] = Off` (errors never bubble to the browser)
  - Slow-log is present but commented out (`request_slowlog_timeout` / `slowlog`), intended for diagnosing stuck RouterOS API calls.
- `deploy/lighttpd/aircoins.conf` — explicitly disables access logging (`mod_accesslog` not loaded) to avoid SD-card wear on SBC deployments.
- `DEPLOYMENT.md` — documents the rationale: "Access logging is disabled (`mod_accesslog` not loaded) and SQLite runs in WAL + `synchronous=NORMAL`. Consider `log2ram` on Armbian/Raspberry Pi OS and a quality/endurance microSD."

## Architecture and conventions

- All PHP errors, warnings, notices, and fatal errors from every script in the app (admin panel, hotspot portal, session API, shared includes) are emitted by PHP itself and appended to `/var/log/php-fpm-aircoins.log`.
- There is no per-module or per-component log separation; it is one flat file.
- No log levels are used within application code — there are no `info/debug/warn/error` calls because none exist.
- Frontend JS uses `console.log` for development-time diagnostics (e.g. `hotspot/assets/js/core.js`, `hotspot/assets/js/eload.js`) but these do not reach the server-side log.

## Conventions and constraints

- **Rule (enforced by PHP-FPM pool config):** Application errors are written to `/var/log/php-fpm-aircoins.log` and are never displayed to the client (`display_errors = Off`). This is enforced by `php_admin_flag[display_errors] = Off` and `php_admin_flag[log_errors] = On` in `deploy/php-fpm/aircoins-pool.conf`.
- **Rule (enforced by lighttpd config):** HTTP access logs are intentionally disabled to reduce SD-card wear on embedded SBCs, as documented in `deploy/lighttpd/aircoins.conf` and `DEPLOYMENT.md`.
- **Constraint (enforced by open_basedir):** The pool's filesystem access is restricted to `/var/www/aircoins:/var/lib/aircoins:/etc/aircoins:/tmp:/var/cache/lighttpd:/var/log/php-fpm-aircoins.log`; any new log sink must be placed under one of these paths or the pool config must be updated accordingly.
- **Convention:** There is no application-level logging convention to follow — developers should rely on PHP's native error reporting rather than adding ad-hoc file writes.