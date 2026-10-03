---
kind: external_dependency
name: OpenSSL — Self-signed TLS certificate generator
slug: openssl
category: external_dependency
category_hints:
    - client_constraint
scope:
    - '**'
source_files:
    - deploy/scripts/install-sbc.sh
    - DEPLOYMENT.md
---

### Identity
OpenSSL is used by the installer to generate a self-signed X.509 certificate for the admin panel.

### Role
- Certificate CN defaults to the SBC hostname; valid for 10 years.
- Written to `/etc/lighttpd/certs/aircoins.pem` (chmod 600) and referenced by lighttpd's `ssl.openssl.ssl-conf-cmd` directive.
- Browsers will show a self-signed cert warning — expected on LAN deployments.

### Client constraint
The REST client (`RestClient.php`) connects to RouterOS `www-ssl` with peer verification disabled so it tolerates the router's own self-signed certs on an isolated hotspot LAN. Only replace the SBC's own cert with a CA-signed one if the admin panel is exposed beyond the trusted LAN.