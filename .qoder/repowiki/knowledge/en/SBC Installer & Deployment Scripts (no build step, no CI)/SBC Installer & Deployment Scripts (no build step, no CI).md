---
kind: build_system
name: SBC Installer & Deployment Scripts (no build step, no CI)
category: build_system
scope:
    - '**'
source_files:
    - deploy/scripts/install-sbc.sh
    - deploy/scripts/update-portal.sh
    - deploy/lighttpd/aircoins.conf
    - deploy/php-fpm/aircoins-pool.conf
    - deploy/mikrotik/hotspot-external-portal.rsc
    - router-stubs/login.html
    - router-stubs/alogin.html
    - router-stubs/error.html
    - router-stubs/logout.html
    - DEPLOYMENT.md
---

## What system/approach is used

AIRCOINS NETFI has **no traditional build step** — the project is a flat collection of PHP/HTML/JS files deployed directly onto a Debian/Ubuntu/Armbian single-board computer. The `DEPLOYMENT.md` explicitly states: *"no framework, no Composer, no build step, low RAM/flash footprint suitable for an Orange Pi / Raspberry Pi running Armbian, Debian bookworm, or Ubuntu 24.04."* There is no Makefile, Dockerfile, CI pipeline, version manifest, or cross-compile toolchain in the repository.

Deployment is performed by two idempotent Bash scripts under `deploy/scripts/`, driven by the user on the target SBC via `sudo bash deploy/scripts/install-sbc.sh` and `sudo bash deploy/scripts/update-portal.sh`. The MikroTik side is configured by importing a RouterOS script (`deploy/mikrotik/hotspot-external-portal.rsc`) and uploading four thin HTML stubs from `router-stubs/` to `/hotspot` on the router.

## Key files and packages

- `deploy/scripts/install-sbc.sh` — full provisioning script (root check, apt install, lighttpd + php-fpm setup, TLS cert, sodium key, SQLite schema, first admin creation, ufw rules, service enable).
- `deploy/scripts/update-portal.sh` — incremental re-sync of `hotspot/`, `admin/`, `includes/`, `api/` with rsync diff detection; reloads php-fpm only when PHP code changed.
- `deploy/lighttpd/aircoins.conf` — lighttpd config template (port 80 portal docroot, port 443 admin docroot, fastcgi to php-fpm socket, `/api/` alias outside docroot, self-signed TLS, 404 fallback).
- `deploy/php-fpm/aircoins-pool.conf` — dedicated `[aircoins]` pool (`pm = ondemand`, `max_children = 5`, `open_basedir` restricted to `/var/www/aircoins:/var/lib/aircoins:/etc/aircoins:/tmp:/var/cache/lighttpd:/var/log/php-fpm-aircoins.log`).
- `deploy/mikrotik/hotspot-external-portal.rsc` — complete RouterOS configuration (addressing, hotspot profile/server, walled garden, ip-binding, services, static DNS, NAT) parameterized by variables at the top (`sbcIP`, `hsInterface`, `hsNet`, `hsPoolRange`, `dnsName`, `wanInterface`).
- `router-stubs/login.html|alogin.html|error.html|logout.html` — thin RouterOS-served redirect pages uploaded to `/hotspot` on the MikroTik.
- `DEPLOYMENT.md` — authoritative deployment guide covering architecture, prerequisites, quick start, RouterOS config, REST vs Legacy API, admin panel walkthrough, portal customization, troubleshooting, and file layout.

## Architecture and conventions

### Target platform detection
The installer detects architecture (`dpkg --print-architecture`), OS (`/etc/os-release` ID + VERSION_CODENAME), and PHP major.minor (prefers already-installed `php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;'`, else maps codenames: bullseye→7.4, bookworm→8.2, trixie→8.4, focal→7.4, jammy→8.1, noble→8.3). It installs versioned `php<FPMVER>-fpm/cli/sqlite3/curl/mbstring` packages and falls back to metapackages if unavailable. Sodium is expected built into PHP core since 7.2; a `php-libsodium` fallback is attempted.

### Deployed directory layout (single source of truth)
The scripts enforce a fixed layout:
```
/var/www/aircoins/
├── portal/          ← hotspot/   (lighttpd :80 docroot)
└── app/
    ├── admin/       ← admin/     (lighttpd :443 docroot)
    ├── includes/    ← includes/  (shared PHP core)
    └── api/         ← api/       (aliased at /api/ on port 80)
/etc/lighttpd/lighttpd.conf
/etc/php/<FPMVER>/fpm/pool.d/aircoins.conf
/run/php/php<FPMVER>-fpm-aircoins.sock
/etc/aircoins/secret.key
/var/lib/aircoins/aircoins.db
```
The layout exists so that `admin/*.php` can `require_once __DIR__.'/../includes/...'`, `admin/api/monitor.php` can `'./../../includes/...'`, and `api/session.php` can `'./../includes/...'` — documented in both `install-sbc.sh` and `DEPLOYMENT.md`.

### Configuration templating
Config files are shipped as templates with `@@PLACEHOLDER@@` tokens substituted by `sed` during install:
- `aircoins.conf`: `@@PORTAL_DOCROOT@@`, `@@ADMIN_DOCROOT@@`, `@@API_DIR@@`, `@@FPM_SOCKET@@`.
- `aircoins-pool.conf`: `@@FPMVER@@`.
This avoids hardcoding paths and lets the scripts derive them from runtime detection.

### Idempotency
Both scripts use `set -euo pipefail`, guard against non-root execution, skip existing artifacts (certificate, sodium key, admin users), and are safe to re-run. The installer backs up the stock lighttpd config once (`lighttpd.conf.aircoins-bak`) before overwriting it.

### Security posture baked into deployment
- Admin site uses a self-signed RSA-2048 certificate valid for 10 years, generated with CN=`hostname -f` (or hostname), stored at `/etc/lighttpd/certs/aircoins.pem` with mode `600`.
- A libsodium secretbox key is generated as `www-data` and written to `/etc/aircoins/secret.key` with mode `0400`.
- Data dirs get `chmod 750` (sessions `700`); PHP error log gets `640`.
- php-fpm runs with `open_basedir` restricted to the app/data/key/tmp/upload/log paths.
- Only ports 80/tcp and 443/tcp are allowed through ufw; SSH is never auto-opened (to avoid locking out remote sessions).

### Router-side integration
The `.rsc` script is the single source of truth for the MikroTik configuration; users edit its top-level variables then run `/import file-name=hotspot-external-portal.rsc`. Four `router-stubs/*.html` files must be manually uploaded to `/hotspot` on the router (the script does not do this automatically).

## Conventions and constraints

- **No build step**: the project ships raw PHP/HTML/JS; there is no compilation, bundling, minification, or artifact generation. `DEPLOYMENT.md` section 1 declares "no framework, no Composer, no build step".
- **Installer-only provisioning**: all SBC setup goes through `deploy/scripts/install-sbc.sh`; manual steps in `DEPLOYMENT.md` section 6.2 are provided as a fallback but mirror what the script does.
- **Idempotent deployment**: both scripts are designed to be re-run safely; they detect and preserve existing certificates, keys, and admin accounts.
- **Fixed deployment paths**: `WWW=/var/www/aircoins`, `PORTAL=$WWW/portal`, `APP=$WWW/app`, `ADMIN=$APP/admin`, `INCLUDES=$APP/includes`, `API=$APP/api`, `DB_DIR=/var/lib/aircoins`, `KEY_DIR=/etc/aircoins`, `CERT_DIR=/etc/lighttpd/certs` — these are the single source of truth for where files land.
- **Source validation**: the installer requires the presence of `hotspot/`, `admin/`, `includes/`, `api/` directories plus `deploy/lighttpd/aircoins.conf` and `deploy/php-fpm/aircoins-pool.conf` in the source repo; missing any causes an immediate `die`.
- **Write-guard**: both `copy_tree` (installer) and `sync_dir` (updater) refuse to write outside `$WWW`, rejecting arbitrary paths passed as arguments.
- **rsync-first, cp fallback**: when `rsync` is available, tree copies use `rsync -a --delete`; otherwise `cp -a` after clearing the destination. This is the same pattern in both scripts.
- **Portal-only updates don't restart services**: `update-portal.sh` tracks whether `includes/`, `admin/`, or `api/` changed (via rsync dry-run itemize grep for `<`/`>` lines) and reloads php-fpm only when PHP code changed; static portal edits take effect immediately because lighttpd serves them from disk.
- **Router stubs are separate from the portal**: `router-stubs/` is not copied by the installer — it must be edited (replacing `sbcIP`) and uploaded manually to the MikroTik's `/hotspot` directory, per `DEPLOYMENT.md` section 7.3.
- **No CI, no Docker, no Makefile, no version manifest**: none of these exist in the repository; releases are distributed as git clones or tarballs of the source tree.