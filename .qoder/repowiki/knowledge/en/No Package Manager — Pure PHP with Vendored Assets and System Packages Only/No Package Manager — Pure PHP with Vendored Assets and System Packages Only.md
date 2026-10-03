---
kind: dependency_management
name: No Package Manager — Pure PHP with Vendored Assets and System Packages Only
category: dependency_management
scope:
    - '**'
source_files:
    - DEPLOYMENT.md
    - deploy/scripts/install-sbc.sh
    - hotspot/assets/js/jquery.min.js
    - hotspot/assets/js/bootstrap.min.js
    - hotspot/assets/js/qrcode.min.js
    - hotspot/assets/js/pako.min.js
    - hotspot/assets/css/bootstrap.min.css
    - hotspot/assets/css/toast.min.css
    - includes/RouterOS/RestClient.php
    - includes/RouterOS/LegacyApiClient.php
---

## Approach

This repository has **no package manager** for PHP dependencies. The project is explicitly designed as a flat, framework-free PHP application: the deployment guide states "no framework, no Composer, no build step" (DEPLOYMENT.md, section 1).

There are no `composer.json`, `composer.lock`, `package.json`, `go.mod`, `Gemfile`, or any other dependency manifest in the repository. Third-party code is brought in by one of two mechanisms:

### 1. Vendored frontend assets (static files)
Third-party JavaScript/CSS libraries are committed directly into `hotspot/assets/`:
- `bootstrap.min.css`, `bootstrap.min.js`, `popper.min.js`
- `jquery.min.js`
- `toast.min.css`, `toast.min.js`
- `qrcode.min.js`
- `pako.min.js`
- `md5.js`

These are minified copies of well-known libraries, checked into version control alongside the application source. There is no lockfile or update script to keep them synchronized with upstream versions.

### 2. System packages via the SBC installer
Runtime dependencies are installed on the target SBC through `deploy/scripts/install-sbc.sh`, which uses `apt-get` to install:
- `lighttpd`
- `php<FPMVER>-{fpm,cli,sqlite3,curl,mbstring,sodium}`
- `openssl`, `ufw`, `ca-certificates`

The installer detects the OS (`dpkg --print-architecture`, `/etc/os-release`) and PHP major version, then installs the matching `php<FPMVER>` metapackages. If versioned packages are unavailable it falls back to versionless ones.

### 3. RouterOS scripts
`deploy/mikrotik/hotspot-external-portal.rsc` is a RouterOS configuration script uploaded to the MikroTik router; it is not a managed dependency but part of the application's deployment payload.

## Key Files

- `DEPLOYMENT.md` — declares the design goal "no framework, no Composer, no build step" and documents all system-level prerequisites.
- `deploy/scripts/install-sbc.sh` — the single point where runtime packages are declared and installed.
- `hotspot/assets/js/*.js`, `hotspot/assets/css/*.css` — vendored third-party frontend libraries.
- `includes/RouterOS/RestClient.php`, `LegacyApiClient.php` — use only PHP built-in extensions (`curl`, `stream_socket_client`); no external PHP libraries.

## Conventions & Constraints

- **No Composer / no autoloader:** All PHP files load each other via relative `require_once` paths (e.g. `admin/*.php` do `require_once __DIR__.'/../includes/...'`). The deployed layout under `/var/www/aircoins/app/` is arranged specifically so these relative includes resolve correctly (documented in DEPLOYMENT.md section 12).
- **No build step:** Frontend assets are edited in place and served directly by lighttpd; there is no bundler, transpiler, or asset pipeline.
- **Frontend library updates are manual:** Since vendor files are committed as plain `.min.js/.css` files, updating a library means replacing the file manually — there is no automated tooling to check for newer versions.
- **System dependency versions are pinned by the installer's apt query:** The installer resolves the running PHP major version and installs `php<FPMVER>-*` packages accordingly; this is the closest thing to a lockfile in the repo.
- **No private registry or proxy configuration:** Dependency installation goes straight to the default APT repositories and GitHub (`git clone https://github.com/Djnirds1984/MT-CONTROLLER-PISOWIFI.git`).