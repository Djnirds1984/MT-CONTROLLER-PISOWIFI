---
kind: external_dependency
name: PHP-FPM — Dedicated pool for AIRCOINS NETFI
slug: php-fpm
category: external_dependency
category_hints:
    - framework_behavior
scope:
    - '**'
source_files:
    - deploy/php-fpm/aircoins-pool.conf
    - DEPLOYMENT.md
---

### Identity
PHP-FPM runs a dedicated `[aircoins]` pool defined in `deploy/php-fpm/aircoins-pool.conf`, installed under `/etc/php/<FPMVER>/fpm/pool.d/aircoins.conf`.

### Role
- Processes all `.php` requests forwarded by lighttpd via UNIX socket `/run/php/php<FPMVER>-fpm-aircoins.sock`.
- Pool mode = `ondemand`, `max_children = 5` — workers spawn only on traffic, targeting low RAM footprint on SBCs.
- Runs as `www-data` with `open_basedir` limited to `/var/www/aircoins:/var/lib/aircoins:/etc/aircoins:/tmp:/var/cache/lighttpd:/var/log/php-fpm-aircoins.log` and `display_errors = Off`.

### Required extensions
`fpm`, `cli`, `sqlite3`, `curl`, `mbstring`, `sodium`. On modern PHP 8.x `sodium` is built into the core runtime — no separate `php-sodium` package exists on most Armbian/Debian repos.

### Deployment shape
The installer auto-detects the PHP version and substitutes `<FPMVER>` into both the lighttpd fastcgi socket path and the pool file. A wrong FPM version between the two files is a common failure mode.