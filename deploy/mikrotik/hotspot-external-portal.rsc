#
# ============================================================================
#  AIRCOINS NETFI — MikroTik Hotspot → External Captive Portal (SBC) setup
#  File: deploy/mikrotik/hotspot-external-portal.rsc
# ============================================================================
#
#  PURPOSE
#  -------
#  Configure a MikroTik router so that hotspot clients are redirected to an
#  EXTERNAL captive-portal panel hosted on an SBC (Orange Pi / Raspberry Pi /
#  any Ubuntu-Debian-Armbian box running lighttpd on port 80).
#
#  The router keeps only thin "stub" pages in its own /hotspot directory
#  (login.html, alogin.html, error.html, logout.html — see router-stubs/).
#  Those stubs immediately meta-refresh the browser to the SBC panel, passing
#  the client context (mac, ip, dst, login URL, logout URL, username, error).
#  Authentication still happens ON the router via HTTP-PAP: the SBC login form
#  POSTs the voucher back to the router's hotspot login URL.
#
#  Redirect flow
#  -------------
#    STA associates -> hotspot challenge -> router serves /hotspot/login.html
#    (stub) -> browser meta-refreshes to  http://<SBC_IP>/login.html?...
#    -> user submits voucher -> form POSTs to router login (HTTP-PAP)
#    -> router serves /hotspot/alogin.html (stub) -> $(link-redirect) = SBC
#    status page -> user is online.
#
#  PREREQUISITES
#  -------------
#   * RouterOS v7 (the /system device-mode gate below is v7-only). The rest of
#     the script is valid on v6 as well, except that device-mode line, which is
#     left commented.
#   * An SBC on the SAME L3 as the hotspot network, holding the static IP given
#     by $sbcIP below, running lighttpd on port 80 serving the portal.
#   * A hotspot interface ($hsInterface) that carries both the wireless/wired
#     clients AND the SBC.
#   * The router-stubs/*.html files uploaded to the router /hotspot directory,
#     replacing the stock login.html, alogin.html, error.html, logout.html
#     (see the final comment block).
#   * WinBox/SSH access with full (admin) privileges — device-mode + services
#     changes require it.
#
#  WHICH API TO ENABLE (for the SBC admin panel to talk to this router)
#  -------------------------------------------------------------------
#   * REST API  -> RouterOS v7 ONLY, service "www-ssl" on TCP 443, HTTPS with
#                  Basic auth, JSON payloads (all values are strings). Enabled
#                  in section 8 below. Requires a certificate.
#   * Legacy API-> RouterOS v6 AND v7, service "api" on TCP 8728 (or "api-ssl"
#                  8729). Binary sentence protocol. Usually already enabled by
#                  default; the enable line is provided commented in section 8.
#   Enable ONLY the one your admin panel is configured to use (or both).
#
#  HOW TO RUN
#  ----------
#   1. Edit the :local variables in the block below to match your network.
#   2. Paste into WinBox "New Terminal" (or SSH) and press Enter, or upload and
#      run with:  /import file-name=hotspot-external-portal.rsc
#   3. Upload the router-stubs/*.html files (final section).
# ============================================================================


# ----------------------------------------------------------------------------
# SECTION 0 — Site variables (EDIT THESE)
# ----------------------------------------------------------------------------
# Everything site-specific lives here so the rest of the script is copy-paste
# safe. Names in $... are referenced throughout.

:local sbcIP        "192.168.88.10"          # SBC panel IP (lighttpd :80). Replace everywhere.
:local hsInterface  "bridge-hotspot"         # Interface the hotspot + clients + SBC live on.
:local hsNet        "192.168.88.0/24"        # Hotspot client network (with mask).
:local hsPool       "hs-pool"                # Name of the DHCP address pool for hotspot clients.
:local dnsName      "hotspot.aircoins.local" # Portal hostname clients resolve to the SBC.

# --- Supporting variables (defaults are fine for 192.168.88.0/24) ------------
:local hsAddress    "192.168.88.1/24"        # Router gateway address on $hsInterface.
:local gwIP         "192.168.88.1"           # Gateway IP without mask (DHCP + hotspot-address).
:local hsPoolRange  "192.168.88.100-192.168.88.254"  # DHCP lease range (SBC IP must be OUTSIDE it).
:local wanInterface "ether1"                 # Uplink/WAN interface for internet masquerade.
:local hsProfile    "aircoins-external"      # Hotspot profile name.
:local hsServer     "aircoins-hotspot"       # Hotspot server name.
:local dhcpName     "aircoins-dhcp"          # DHCP server name.


# ----------------------------------------------------------------------------
# SECTION 1 — Device-mode gate (RouterOS v7 ONLY)
# ----------------------------------------------------------------------------
# On RouterOS v7 the "hotspot" feature is gated by device-mode. If it is not
# enabled, /ip hotspot commands and the hotspot server will not work.
#
# >>> RouterOS v7 users: UNCOMMENT the line below and re-run it once. <<<
# >>> RouterOS v6 users: leave it commented (device-mode does not exist on v6). <<<
# Setting device-mode may require the change to be applied by an admin session;
# on some units a reboot is recommended afterwards.
#
# /system device-mode set hotspot=yes


# ----------------------------------------------------------------------------
# SECTION 2 — Base addressing: gateway IP, DHCP pool, DHCP server + network
# ----------------------------------------------------------------------------
# Router IP on the hotspot interface (client default gateway).
/ip address add address=$hsAddress interface=$hsInterface network=$hsNet \
    comment="AIRCOINS hotspot gateway"

# Address pool handed out to hotspot clients. Keep $sbcIP outside this range.
/ip pool add name=$hsPool ranges=$hsPoolRange

# DHCP server bound to the hotspot interface, leasing from the pool above.
/ip dhcp-server add name=$dhcpName interface=$hsInterface address-pool=$hsPool \
    lease-time=1d bootp-support=none disabled=no comment="AIRCOINS hotspot DHCP"

# DHCP network record: gateway + DNS point at the router (router will resolve
# $dnsName to the SBC via the static entry in section 9).
/ip dhcp-server network add address=$hsNet gateway=$gwIP dns-server=$gwIP \
    comment="AIRCOINS hotspot net"

# Allow the router to answer DNS queries from clients (needed for the static
# portal hostname in section 9 to resolve for hotspot clients).
/ip dns set allow-remote-requests=yes


# ----------------------------------------------------------------------------
# SECTION 3 — Hotspot profile (HTTP-PAP + cookie)
# ----------------------------------------------------------------------------
# login-by=http-pap,cookie :
#   * http-pap  -> the SBC login form POSTs the voucher in PLAINTEXT over HTTP.
#                  CHAP is impractical for an external page because the challenge
#                  is generated per-request by the router, so PAP is required.
#   * cookie    -> keeps the client authenticated across the browser session via
#                  the hotspot HTTP cookie (no repeated logins on the same device).
# dns-name        -> the hostname clients are redirected to / that resolves to the
#                    SBC (static entry added in section 9).
# hotspot-address -> the router address the hotspot answers on ($gwIP).
# http-cookie-lifetime=1d -> cookie validity.
/ip hotspot profile add name=$hsProfile login-by=http-pap,cookie \
    dns-name=$dnsName hotspot-address=$gwIP http-cookie-lifetime=1d \
    html-directory=hotspot use-radius=no comment="AIRCOINS external portal profile"


# ----------------------------------------------------------------------------
# SECTION 4 — Hotspot server on the interface (pool + profile)
# ----------------------------------------------------------------------------
/ip hotspot add name=$hsServer interface=$hsInterface address-pool=$hsPool \
    profile=$hsProfile disabled=no comment="AIRCOINS external-portal hotspot"


# ----------------------------------------------------------------------------
# SECTION 5 — Walled garden: let UNAUTHENTICATED clients reach the SBC portal
# ----------------------------------------------------------------------------
# /ip hotspot walled-garden ip uses action=accept (NOT "allow" — that keyword is
# only valid on the non-ip "/ip hotspot walled-garden" host-name rules).
# This lets a client that has NOT logged in yet open http://<SBC_IP>/ (port 80)
# so the login page itself can load before authentication.
/ip hotspot walled-garden ip add action=accept dst-address=$sbcIP dst-port=80 \
    protocol=tcp comment="AIRCOINS SBC portal"

# Optional: also allow the SBC admin panel over HTTPS (443) from the walled
# garden IF admins browse it from the hotspot side. Usually NOT needed because
# admin traffic comes from the management network — uncomment only if required.
# /ip hotspot walled-garden ip add action=accept dst-address=$sbcIP dst-port=443 \
#     protocol=tcp comment="AIRCOINS SBC admin (HTTPS)"


# ----------------------------------------------------------------------------
# SECTION 6 — IP binding: exempt the SBC from hotspot interception entirely
# ----------------------------------------------------------------------------
# type=bypassed means the router never redirects the SBC's own traffic through
# the hotspot and never challenges it — the SBC is always reachable and can
# always talk to the router API. This prevents redirect loops between the router
# stub and the SBC portal.
/ip hotspot ip-binding add address=$sbcIP type=bypassed comment="SBC exempt"


# ----------------------------------------------------------------------------
# SECTION 7 — Services for the SBC admin panel (REST and/or Legacy API)
# ----------------------------------------------------------------------------
# --- REST API (RouterOS v7) -------------------------------------------------
# www-ssl serves the REST API over HTTPS on TCP 443 (Basic auth, JSON).
# NOTE: www-ssl needs a certificate. RouterOS will fall back to an auto-generated
# self-signed cert, but it is cleaner to create/assign your own, e.g.:
#   /certificate add name=aircoins-rest-cert common-name=$dnsName days-valid=3650 \
#       key-size=2048 trusted=yes
#   /certificate create-certificate ... (or import a CA-signed cert)
#   /ip service set www-ssl certificate=aircoins-rest-cert
# The SBC REST client should disable peer verification (self-signed) per the plan.
/ip service enable www-ssl

# --- Legacy binary API (RouterOS v6 + v7) -----------------------------------
# Service "api" on TCP 8728 (plaintext) / "api-ssl" on 8729 (TLS). This is
# usually ALREADY enabled by default on RouterOS. Uncomment only if you disabled
# it or want to be explicit. Enable this INSTEAD OF / IN ADDITION TO www-ssl
# depending on which API type the admin panel uses for this router.
# /ip service enable api
# /ip service enable api-ssl


# ----------------------------------------------------------------------------
# SECTION 8 — Static DNS: portal hostname -> SBC IP
# ----------------------------------------------------------------------------
# So that clients asking for $dnsName are answered with the SBC address. This
# lets you use a friendly portal URL (e.g. http://hotspot.aircoins.local) that
# points at the SBC, matching the profile dns-name in section 3.
/ip dns static add name=$dnsName address=$sbcIP comment="AIRCOINS portal hostname -> SBC"


# ----------------------------------------------------------------------------
# SECTION 9 — Internet NAT (masquerade out the WAN interface)
# ----------------------------------------------------------------------------
# Authenticated hotspot clients reach the internet through the WAN uplink.
/ip firewall nat add chain=srcnat out-interface=$wanInterface action=masquerade \
    comment="AIRCOINS hotspot internet NAT"


# ============================================================================
# SECTION 10 — FINAL STEP: UPLOAD THE ROUTER STUB PAGES
# ============================================================================
# The router still needs its own thin stub pages so the redirect chain starts
# and ends on the router. Upload the files from router-stubs/ into the router's
# /hotspot directory (WinBox: Files -> drag & drop, or FTP/SFTP), REPLACING the
# stock pages of the same name:
#
#     router-stubs/login.html   ->  /hotspot/login.html
#     router-stubs/alogin.html  ->  /hotspot/alogin.html
#     router-stubs/error.html   ->  /hotspot/error.html
#     router-stubs/logout.html  ->  /hotspot/logout.html
#
# Before uploading, edit each stub and replace 192.168.88.10 with your real
# $sbcIP (the SBC panel IP). login.html MUST keep the marker comment
# <!-- IAMNOTLOGINSTRINGPLEASEDONTREMOVE --> so RouterOS still treats it as the
# hotspot login page.
#
# The heavier portal assets (css/js/img, status.html, the JuanFi assets, the
# PHP backend, etc.) live ONLY on the SBC — they are NOT uploaded to the router.
# ============================================================================
