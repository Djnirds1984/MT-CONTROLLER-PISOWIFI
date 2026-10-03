---
kind: external_dependency
name: MikroTik RouterOS — Hotspot controller & API target
slug: mikrotik-routeros
category: external_dependency
category_hints:
    - sdk_real_api
    - client_constraint
scope:
    - '**'
source_files:
    - includes/RouterOS/RouterClientInterface.php
    - includes/RouterOS/RestClient.php
    - includes/RouterOS/LegacyApiClient.php
    - includes/RouterOS/RouterFactory.php
    - deploy/mikrotik/hotspot-external-portal.rsc
    - router-stubs/login.html
---

### Identity
MikroTik RouterOS (v6 or v7) is the hotspot controller that authenticates clients and exposes two APIs consumed by the admin panel.

### Role in this repo
The SBC serves the captive portal; all hotspot user/voucher/session operations go through the router's API — never SSH or screen-scraping. Two parallel client implementations exist:
- `RestClient.php` — HTTPS Basic-auth JSON API (`www-ssl`, port 443), RouterOS v7 only.
- `LegacyApiClient.php` — binary sentence protocol over TCP 8728 (plaintext) or 8729 (TLS), v6+v7.
Both are selected via `RouterFactory.php` from a per-router `api_type` field.

### Integration points
- `deploy/mikrotik/hotspot-external-portal.rsc` — paste-ready RouterOS script configuring hotspot profile (`login-by=http-pap,cookie`), walled garden, ip-binding, services, static DNS, NAT.
- `router-stubs/*.html` — four thin redirect pages uploaded to the router's `/hotspot` directory; they meta-refresh to the SBC portal carrying `$(mac-esc)`, `$(ip-esc)`, `$(link-login-only-esc)`, etc.
- `hotspot/js/varbridge.js` — client-side bridge that fills `$(var)` tokens from query params when the portal is served off the SBC.

### Stable integration shape
- REST: `GET`/`PUT`(=add)/`PATCH`(=set)/`DELETE` against `https://host:443/rest/...`; item addressing uses `.id=*HEX` without percent-encoding.
- Portal auth path: voucher form POSTs HTTP-PAP back to the router's login URL; cookie keeps the session alive.

Verify exact REST paths and legacy verbs against the official RouterOS API docs before changing either client.