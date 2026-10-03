---
kind: external_dependency
name: lighttpd — Web server serving portal (:80) and admin (:443)
slug: lighttpd
category: external_dependency
category_hints:
    - framework_behavior
scope:
    - '**'
source_files:
    - deploy/lighttpd/aircoins.conf
    - DEPLOYMENT.md
---

### Identity
lighttpd is the sole web server on the SBC, configured via `deploy/lighttpd/aircoins.conf`.

### Role
- Port **80** docroot = `hotspot/` (pure HTML captive portal).
- Port **443** docroot = `admin/` (PHP-FPM admin panel, self-signed TLS).
- `.php` requests proxied to php-fpm over a UNIX socket (`fastcgi.server`).
- `/api/` alias on port 80 maps `/api/session.php` to `app/api/session.php` outside the portal docroot (`check-local = disable`, `broken-scriptfilename = enable`).

### Deployment shape
- Installed as the primary config (stock `lighttpd.conf` backed up to `lighttpd.conf.aircoins-bak`).
- Requires the separate `lighttpd-mod-openssl` package on Debian/Armbian for `mod_openssl.so`.
- Self-signed cert written to `/etc/lighttpd/certs/aircoins.pem` (chmod 600).
- Admin variant under plain HTTP (`/admin/`) is commented out in the config — it exists but is explicitly warned against because credentials travel in cleartext.

### Known gotcha
On Armbian/Debian the base `lighttpd` package does not ship `mod_openssl.so`; install `lighttpd-mod-openssl` separately. Port conflicts with stock lighttpd / apache2 / nginx / dnsmasq are common — the installer disables apache2/nginx but leaves others for the operator.