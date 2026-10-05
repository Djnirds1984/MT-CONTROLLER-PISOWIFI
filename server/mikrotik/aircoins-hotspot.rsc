# AIRCOINS NETFI — MikroTik hotspot binding script (RouterOS v6/v7)
#
# MANUAL FALLBACK for the in-panel configurator (Admin > MikroTik setup).
# Use this only when the panel cannot reach the router's REST API yet.
# Paste into New Terminal (WinBox/WebFig) or /import file-name=...
#
# What it does (identical to the panel's "Apply configuration"):
#   1. Registers this panel's FreeRADIUS as the hotspot RADIUS server.
#   2. Creates/updates the "aircoins" hotspot profile: use-radius=yes,
#      login-by=http-pap,cookie (CHAP cannot match stored voucher codes
#      and causes "form expired" login loops), html-directory=flash/hotspot
#      for the thin redirect stubs.
#   3. Points the hotspot server on the chosen interface at that profile.
#   4. Walled-garden: unauthenticated clients can reach the SBC portal
#      (tcp/80) — otherwise the login page itself is unreachable.
#   5. IP-binding: the SBC bypasses the hotspot entirely.
#
# Everything is tagged comment="aircoins" and replaced on re-run, so the
# script is safe to import repeatedly.

# --------------------------- edit these three ---------------------------
:local SBCIP   "10.0.0.252"
:local SECRET  "CHANGE-ME-8-to-64-chars-no-spaces"
:local HSIF    "bridge-lan"
# HSIF = the interface your hotspot serves (e.g. bridge-lan, wlan1).
# The SECRET must equal the one in /etc/freeradius/.../clients.conf
# and the RADIUS shared secret stored in the panel (Admin > Settings).
# ------------------------------------------------------------------------

# 1. RADIUS client entry (this panel is the authentication server).
/radius remove [find where comment="aircoins"]
/radius add service=hotspot address=$SBCIP secret=$SECRET \
    authentication-port=1812 accounting-port=1813 accounting=yes \
    comment="aircoins"

# 2. Hotspot profile: RADIUS auth + PAP-only login + stub directory.
:if ([:len [/ip/hotspot/profile find where name="aircoins"]] = 0) do={
    /ip/hotspot/profile add name="aircoins"
}
/ip/hotspot/profile set [find where name="aircoins"] \
    use-radius=yes login-by=http-pap,cookie html-directory=flash/hotspot

# 3. Hotspot server(s) on the chosen interface use the profile.
:if ([:len [/ip/hotspot find where interface=$HSIF]] = 0) do={
    /ip/hotspot add interface=$HSIF profile="aircoins" \
        comment="aircoins-created; ensure DHCP serves this interface"
}
/ip/hotspot set [find where interface=$HSIF] profile="aircoins" disabled=no

# 4. Walled garden: portal (and the coin API on the same host) reachable
#    BEFORE login. Without this rule clients can never see the login page.
/ip/hotspot/walled-garden/ip remove [find where comment="aircoins"]
/ip/hotspot/walled-garden/ip add action=accept dst-address=$SBCIP \
    protocol=tcp dst-port=80 comment="aircoins"
#    Coin insertion runs on the portal page BEFORE login and talks
#    straight to each vendo's NodeMCU on the LAN; accept the whole LAN
#    (tcp) so no vendo needs its own rule. Edit LANNET if yours differs.
:local LANNET "10.0.0.0/24"
/ip/hotspot/walled-garden/ip add action=accept dst-address=$LANNET \
    protocol=tcp comment="aircoins"

# 5. The SBC itself never gets hotspot-redirected.
/ip/hotspot/ip-binding remove [find where comment="aircoins"]
/ip/hotspot/ip-binding add type=bypassed address=$SBCIP comment="aircoins"

# The panel talks to the router over REST; make sure http service is on.
/ip service set www disabled=no

:put "aircoins: router bound to $SBCIP on $HSIF."
:put "aircoins: next — upload the redirect stubs (Admin > Tools), and"
:put "aircoins: confirm FreeRADIUS clients.conf lists THIS router's"
:put "aircoins: source IP with the same secret."
