#
# ============================================================================
#  AIRCOINS NETFI — MikroTik hEX GR3 Hotspot → External Captive Portal (SBC)
#  File: deploy/mikrotik/hotspot-external-portal.rsc
# ============================================================================
#
#  TARGET HARDWARE
#  ---------------
#  MikroTik hEX GR3 (RB750Gr3) — 5x Gigabit Ethernet, no WiFi, RouterOS v7.
#
#  TOPOLOGY
#  --------
#    ether1  = WAN (uplink to ISP / main LAN 10.0.0.0/24)
#    bridge-lan = ether2 + ether3 + ether4 + ether5 (hotspot LAN)
#    SBC     = 10.0.0.252 on the WAN side (reachable via bridge-lan routing)
#
#  PURPOSE
#  -------
#  Configure the hEX GR3 so that hotspot clients on the bridge-lan are
#  redirected to an EXTERNAL captive-portal panel hosted on the SBC
#  (FreeRADIUS + lighttpd on port 80).
#
#  The router keeps only thin "stub" pages in its own /hotspot directory
#  (login.html, alogin.html, error.html, logout.html — see router-stubs/).
#  Those stubs immediately meta-refresh the browser to the SBC panel, passing
#  the client context (mac, ip, dst, login URL, logout URL, username, error).
#
#  Authentication flow (RADIUS):
#    Client -> hotspot challenge -> router serves /hotspot/login.html (stub)
#    -> browser meta-refreshes to http://<SBC_IP>/login.html?...
#    -> user submits voucher -> form POSTs to router login (HTTP-PAP)
#    -> router sends RADIUS Access-Request to SBC FreeRADIUS
#    -> FreeRADIUS checks SQLite -> Access-Accept with Session-Timeout
#    -> router grants session
#
#  PREREQUISITES
#  -------------
#   * RouterOS v7.24+ (the /system device-mode gate below is v7-only).
#   * The SBC at $sbcIP, running lighttpd :80 + FreeRADIUS with SQLite.
#   * The router-stubs/*.html files uploaded to the router /hotspot directory,
#     replacing the stock login.html, alogin.html, error.html, logout.html.
#   * WinBox/SSH access with full (admin) privileges.
#
#  HOW TO RUN
#  ----------
#   1. Edit the :local variables in SECTION 0 if your network differs.
#   2. Paste into WinBox "New Terminal" (or SSH) and press Enter, or upload and
#      run with:  /import file-name=hotspot-external-portal.rsc
#   3. Upload the router-stubs/*.html files (final section).
# ============================================================================


# ----------------------------------------------------------------------------
# SECTION 0 — Site variables (EDIT THESE if your network differs)
# ----------------------------------------------------------------------------
# Everything site-specific lives here so the rest of the script is copy-paste
# safe. Names in $... are referenced throughout.

# --- Primary variables (change to match your deployment) ---------------------
:local sbcIP        "10.0.0.252"           # SBC panel IP (lighttpd :80 + FreeRADIUS).
:local wanInterface "ether1"               # Uplink/WAN interface (to ISP or main LAN).
:local lanPorts     "ether2,ether3,ether4,ether5"  # Ports bridged for hotspot LAN.
:local bridgeName   "bridge-lan"           # Name of the LAN bridge interface.
:local hsNet        "192.168.88.0/24"      # Hotspot client network (with mask).
:local hsPool       "hs-pool"              # Name of the DHCP address pool.
:local dnsName      "hotspot.aircoins.local" # Portal hostname clients resolve to the SBC.

# --- Supporting variables (defaults are fine for 192.168.88.0/24) ------------
:local hsAddress    "192.168.88.1/24"      # Router gateway address on bridge-lan.
:local gwIP         "192.168.88.1"         # Gateway IP without mask (DHCP + hotspot-address).
:local hsPoolRange  "192.168.88.100-192.168.88.254" # DHCP lease range (SBC IP must be OUTSIDE it).
:local hsProfile    "aircoins-external"    # Hotspot profile name.
:local hsServer     "aircoins-hotspot"     # Hotspot server name.
:local dhcpName     "aircoins-dhcp"        # DHCP server name.
:local radiusSecret "aircoins_secret"      # Shared secret (must match SBC /etc/freeradius/3.0/clients.conf).


# ----------------------------------------------------------------------------
# SECTION 1 — Device-mode gate (RouterOS v7 ONLY)
# ----------------------------------------------------------------------------
# On RouterOS v7 the "hotspot" feature is gated by device-mode. If it is not
# enabled, /ip hotspot commands and the hotspot server will not work.
#
# >>> UNCOMMENT the line below and re-run it once. <<<
# Setting device-mode may require a reboot afterwards.
#
# /system device-mode set hotspot=yes


# ----------------------------------------------------------------------------
# SECTION 2 — Create LAN bridge (hEX GR3 has no bridge by default)
# ----------------------------------------------------------------------------
# The hEX GR3 ships with 5 independent Ethernet ports. We bridge ether2-ether5
# to form the hotspot LAN. ether1 stays as the standalone WAN uplink.
#
# Skip this section if a bridge already exists on your router.
/interface bridge add name=$bridgeName comment="AIRCOINS hotspot LAN bridge"
/interface bridge port add bridge=$bridgeName interface=ether2 comment="AIRCOINS LAN port 2"
/interface bridge port add bridge=$bridgeName interface=ether3 comment="AIRCOINS LAN port 3"
/interface bridge port add bridge=$bridgeName interface=ether4 comment="AIRCOINS LAN port 4"
/interface bridge port add bridge=$bridgeName interface=ether5 comment="AIRCOINS LAN port 5"


# ----------------------------------------------------------------------------
# SECTION 3 — Base addressing: gateway IP, DHCP pool, DHCP server + network
# ----------------------------------------------------------------------------
# Router IP on the bridge-lan interface (client default gateway).
/ip address add address=$hsAddress interface=$bridgeName network=$hsNet \
    comment="AIRCOINS hotspot gateway"

# Address pool handed out to hotspot clients. Keep $sbcIP outside this range.
/ip pool add name=$hsPool ranges=$hsPoolRange

# DHCP server bound to the bridge-lan, leasing from the pool above.
/ip dhcp-server add name=$dhcpName interface=$bridgeName address-pool=$hsPool \
    lease-time=1d bootp-support=none disabled=no comment="AIRCOINS hotspot DHCP"

# DHCP network record: gateway + DNS point at the router (router will resolve
# $dnsName to the SBC via the static entry in section 9).
/ip dhcp-server network add address=$hsNet gateway=$gwIP dns-server=$gwIP \
    comment="AIRCOINS hotspot net"

# Allow the router to answer DNS queries from clients (needed for the static
# portal hostname in section 9 to resolve for hotspot clients).
/ip dns set allow-remote-requests=yes


# ----------------------------------------------------------------------------
# SECTION 4 — Hotspot profile (HTTP-PAP + cookie + RADIUS)
# ----------------------------------------------------------------------------
# login-by=http-pap,cookie :
#   * http-pap  -> the SBC login form POSTs the voucher in PLAINTEXT over HTTP.
#                  CHAP is impractical for an external page because the challenge
#                  is generated per-request by the router, so PAP is required.
#   * cookie    -> keeps the client authenticated across the browser session via
#                  the hotspot HTTP cookie (no repeated logins on the same device).
# use-radius=yes -> the router sends RADIUS Access-Request to the SBC for
#                  authentication instead of checking local hotspot users.
#                  FreeRADIUS on the SBC validates credentials against SQLite.
# dns-name        -> the hostname clients are redirected to / that resolves to the
#                    SBC (static entry added in section 9).
# hotspot-address -> the router address the hotspot answers on ($gwIP).
# http-cookie-lifetime=1d -> cookie validity.
/ip hotspot profile add name=$hsProfile login-by=http-pap,cookie \
    dns-name=$dnsName hotspot-address=$gwIP http-cookie-lifetime=1d \
    html-directory=hotspot use-radius=yes comment="AIRCOINS external portal profile"


# ----------------------------------------------------------------------------
# SECTION 5 — Hotspot server on the bridge (pool + profile)
# ----------------------------------------------------------------------------
/ip hotspot add name=$hsServer interface=$bridgeName address-pool=$hsPool \
    profile=$hsProfile disabled=no comment="AIRCOINS external-portal hotspot"


# ----------------------------------------------------------------------------
# SECTION 6 — Walled garden: let UNAUTHENTICATED clients reach the SBC portal
# ----------------------------------------------------------------------------
# /ip hotspot walled-garden ip uses action=accept (NOT "allow" — that keyword is
# only valid on the non-ip "/ip hotspot walled-garden" host-name rules).
# This lets a client that has NOT logged in yet reach the SBC on port 80
# so the login page itself can load before authentication.
# Since the SBC (10.0.0.252) is on a DIFFERENT subnet than the hotspot clients
# (192.168.88.0/24), this rule is REQUIRED — the router must permit the traffic.
/ip hotspot walled-garden ip add action=accept dst-address=$sbcIP dst-port=80 \
    protocol=tcp comment="AIRCOINS SBC portal (HTTP)"

# Also allow the SBC admin panel over HTTPS (443) from the walled garden
# so admins can reach https://<SBC_IP>/ from the hotspot side.
/ip hotspot walled-garden ip add action=accept dst-address=$sbcIP dst-port=443 \
    protocol=tcp comment="AIRCOINS SBC admin (HTTPS)"


# ----------------------------------------------------------------------------
# SECTION 7 — RADIUS server configuration
# ----------------------------------------------------------------------------
# Point the router's RADIUS client at the SBC where FreeRADIUS runs.
# The shared secret must match /etc/freeradius/3.0/clients.conf on the SBC.
# Service=hotspot means this RADIUS server is used for hotspot authentication.
/radius add service=hotspot address=$sbcIP secret=$radiusSecret \
    timeout=3s authentication-port=1812 accounting-port=1813 \
    comment="AIRCOINS SBC FreeRADIUS"

# Enable RADIUS accounting for hotspot users so the SBC can track sessions.
/ip hotspot user profile set default use-radius=yes


# ----------------------------------------------------------------------------
# SECTION 8 — IP binding: exempt the SBC from hotspot interception entirely
# ----------------------------------------------------------------------------
# type=bypassed means the router never redirects the SBC's own traffic through
# the hotspot and never challenges it — the SBC is always reachable and can
# always talk to the router API. This prevents redirect loops.
# NOTE: The SBC is on the WAN side (10.0.0.252), not on bridge-lan, so this
# rule prevents the hotspot from intercepting return traffic to the SBC.
/ip hotspot ip-binding add address=$sbcIP type=bypassed comment="SBC exempt"


# ----------------------------------------------------------------------------
# SECTION 9 — Services for the SBC admin panel (REST API)
# ----------------------------------------------------------------------------
# --- REST API (RouterOS v7) -------------------------------------------------
# www-ssl serves the REST API over HTTPS on TCP 443 (Basic auth, JSON).
# NOTE: www-ssl needs a certificate. RouterOS will fall back to an auto-generated
# self-signed cert, but it is cleaner to create/assign your own, e.g.:
#   /certificate add name=aircoins-rest-cert common-name=$dnsName days-valid=3650 \
#       key-size=2048 trusted=yes
#   /ip service set www-ssl certificate=aircoins-rest-cert
# The SBC REST client should disable peer verification (self-signed).
/ip service enable www-ssl

# --- Also enable plain-HTTP www service (port 80) for the SBC REST client ----
# The operator's panel uses REST over plain HTTP on port 80 (not HTTPS).
/ip service enable www


# ----------------------------------------------------------------------------
# SECTION 10 — Static DNS: portal hostname -> SBC IP
# ----------------------------------------------------------------------------
# So that clients asking for $dnsName are answered with the SBC address. This
# lets you use a friendly portal URL (e.g. http://hotspot.aircoins.local) that
# points at the SBC, matching the profile dns-name in section 4.
/ip dns static add name=$dnsName address=$sbcIP comment="AIRCOINS portal hostname -> SBC"


# ----------------------------------------------------------------------------
# SECTION 11 — Internet NAT (masquerade out the WAN interface)
# ----------------------------------------------------------------------------
# Authenticated hotspot clients reach the internet through the WAN uplink.
/ip firewall nat add chain=srcnat out-interface=$wanInterface action=masquerade \
    comment="AIRCOINS hotspot internet NAT"


# ============================================================================
# SECTION 12 — FINAL STEP: UPLOAD THE ROUTER STUB PAGES
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
# Before uploading, edit each stub and replace 10.0.0.252 with your real
# $sbcIP (the SBC panel IP). login.html MUST keep the marker comment
# <!-- IAMNOTLOGINSTRINGPLEASEDONTREMOVE --> so RouterOS still treats it as the
# hotspot login page.
#
# The heavier portal assets (css/js/img, status.html, the JuanFi assets, the
# PHP backend, etc.) live ONLY on the SBC — they are NOT uploaded to the router.
# ============================================================================
