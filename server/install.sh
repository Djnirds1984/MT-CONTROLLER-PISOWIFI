#!/usr/bin/env bash
# ============================================================================
# AIRCOINS NETFI — SBC installer
#
# Installs the full stack on Ubuntu 20.04+ / Debian 11+ / Armbian:
#   lighttpd (port 80 only: portal at /, admin SPA under /admin)
#   aircoind (Go JSON API on 127.0.0.1:8088, proxied by lighttpd)
#   FreeRADIUS + SQLite (shared radius.db; the MikroTik router is the NAS)
#
# Idempotent: safe to re-run; existing data and settings are preserved.
# Usage:
#   sudo bash install.sh [options]
# Options:
#   --prebuilt FILE     install this aircoind binary instead of building
#   --data DIR          data directory        (default /var/lib/aircoins)
#   --listen ADDR       API listen address    (default 127.0.0.1:8088)
#   --admin USER:PASS   admin login           (prompted otherwise)
#   --router-ip IP      router source IP for clients.conf (default 10.0.0.1)
#   --radius-secret S   RADIUS shared secret  (generated otherwise)
#   --sbc-url URL       portal URL as the router sees it (auto-detected)
#   --portal-name NAME  portal title          (default AIRCOINS NETFI)
#   --yes               unattended: accept defaults, generate passwords
# ============================================================================
set -euo pipefail

SRC="$(cd "$(dirname "$0")" && pwd)"
GO_VERSION="1.22.5"
DATA_DIR="/var/lib/aircoins"
LISTEN="127.0.0.1:8088"
PORTAL_ROOT="/var/www/aircoins/portal"
ADMIN_ROOT="/var/www/aircoins/admin"
BIN="/usr/local/bin/aircoind"
UNIT="/etc/systemd/system/aircoind.service"

PREBUILT=""
ASSUME_YES=0
ADMIN_USER="admin"
ADMIN_PASS=""
ROUTER_IP="10.0.0.1"
ROUTER_USER=""
ROUTER_PASS=""
RADIUS_SECRET=""
LOCAL_SECRET=""
SBC_URL=""
PORTAL_NAME="AIRCOINS NETFI"

# ---------------------------------------------------------------- helpers --

log()  { printf '\033[1;36m[aircoins]\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m[warn]\033[0m %s\n' "$*"; }
die()  { printf '\033[1;31m[error]\033[0m %s\n' "$*" >&2; exit 1; }

ver_ge() { [ "$(printf '%s\n' "$1" "$2" | sort -V | head -1)" = "$2" ]; }

# SIGPIPE-safe on purpose: the script runs under `set -o pipefail`, and a
# pipeline like `tr < /dev/urandom | head -c N` lets head exit early, so tr
# dies with 141 on its next write and set -e silently aborts the installer.
# Bounded producer -> every consumer reads to EOF -> no SIGPIPE, ever.
rand_str() {
	local s
	s="$(head -c 256 /dev/urandom | base64 | tr -dc 'A-Za-z0-9')" || true
	printf '%s' "${s:0:${1:-20}}"
}

ask() { # ask VARNAME "prompt" "default"
	local __var="$1" __prompt="$2" __def="${3:-}" in
	if [ "$ASSUME_YES" = 1 ]; then
		eval "$__var=\"\$__def\""
	else
		read -r -p "$__prompt [$__def]: " in
		[ -z "${in:-}" ] && in="$__def"
		eval "$__var=\"\$in\""
	fi
}

# ------------------------------------------------------------------ args ---

while [ $# -gt 0 ]; do
	case "$1" in
		--prebuilt)      PREBUILT="$2"; shift 2 ;;
		--data)          DATA_DIR="$2"; shift 2 ;;
		--listen)        LISTEN="$2"; shift 2 ;;
		--admin)         ADMIN_USER="${2%%:*}"; ADMIN_PASS="${2#*:}"; shift 2 ;;
		--router-ip)     ROUTER_IP="$2"; shift 2 ;;
		--radius-secret) RADIUS_SECRET="$2"; shift 2 ;;
		--sbc-url)       SBC_URL="$2"; shift 2 ;;
		--portal-name)   PORTAL_NAME="$2"; shift 2 ;;
		--yes|-y)        ASSUME_YES=1; shift ;;
		-h|--help)       sed -n '2,20p' "$0"; exit 0 ;;
		*) die "unknown option: $1" ;;
	esac
done

[ "$(id -u)" -eq 0 ] || die "run as root: sudo bash $0"
[ -f "$SRC/go/main.go" ]        || die "run from the server/ directory of the repo"
[ -f "$SRC/db/radius-schema.sql" ] || die "missing db/radius-schema.sql"
[ -f "$SRC/conf/lighttpd/aircoins.conf" ] || die "missing conf/lighttpd/aircoins.conf"
command -v apt-get >/dev/null 2>&1 || die "this installer targets apt-based systems (Ubuntu/Debian/Armbian)"

LAN_IP="$(ip -4 route get 1.1.1.1 2>/dev/null | awk '{print $7; exit}')"
[ -n "$LAN_IP" ] || LAN_IP="$(hostname -I 2>/dev/null | awk '{print $1}')"
[ -n "$LAN_IP" ] || LAN_IP="127.0.0.1"
[ -n "$SBC_URL" ] || SBC_URL="http://$LAN_IP"

log "portal will be http://$LAN_IP/  admin http://$LAN_IP/admin/"

# ---------------------------------------------------------------- packages -

log "installing packages (lighttpd freeradius sqlite3)..."
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get install -y -qq lighttpd freeradius sqlite3 curl ca-certificates freeradius-utils >/dev/null

# The sqlite RADIUS driver ships inside the base freeradius package; there
# is NO freeradius-sqlite/-sqlite3 apt package — do not look for one.
if dpkg -L freeradius 2>/dev/null | grep -qi 'rlm_sql_sqlite'; then
	log "rlm_sql_sqlite driver present (bundled in freeradius)"
else
	warn "rlm_sql_sqlite not listed in the freeradius package — check dpkg -L freeradius"
fi

for svc in apache2 nginx; do
	if systemctl is-active --quiet "$svc" 2>/dev/null; then
		warn "disabling $svc (port 80 conflict)"
		systemctl disable --now "$svc" >/dev/null 2>&1 || true
	fi
done

# ------------------------------------------------------------------ user ---

if ! id aircoins >/dev/null 2>&1; then
	useradd -r -M -d "$DATA_DIR" -s /usr/sbin/nologin aircoins
	log "created system user aircoins"
fi

# ------------------------------------------------------------- data + DBs --

log "preparing $DATA_DIR ..."
mkdir -p "$DATA_DIR"
chown aircoins:aircoins "$DATA_DIR"
chmod 2775 "$DATA_DIR"                     # setgid: new files keep the group

# radius.db must exist (with FreeRADIUS's tables) BEFORE freeradius starts.
sqlite3 "$DATA_DIR/radius.db" < "$SRC/db/radius-schema.sql"
chown aircoins:aircoins "$DATA_DIR"/radius.db*
chmod 664 "$DATA_DIR"/radius.db* 2>/dev/null || true

# FreeRADIUS writes accounting into the shared db: put its daemon in the
# aircoins group (directory is group-writable, WAL files are 664).
if id freerad >/dev/null 2>&1; then
	usermod -aG aircoins freerad
	log "freerad added to the aircoins group"
fi

# ------------------------------------------------------------ go toolchain -

go_major_min() { command -v go >/dev/null 2>&1 || return 1; go env GOVERSION 2>/dev/null | tr -dc '0-9.'; }

build_binary() {
	local have
	have="$(go_major_min || true)"
	if [ -n "$have" ] && ver_ge "$have" "1.21"; then
		log "using existing Go toolchain ($(go env GOVERSION))"
	else
		if apt-get install -y -qq golang-go >/dev/null 2>&1; then
			have="$(go_major_min || true)"
		fi
		if [ -z "$have" ] || ! ver_ge "$have" "1.21"; then
			log "installing Go $GO_VERSION toolchain (distro Go is $have)..."
			local arch
			case "$(dpkg --print-architecture)" in
				amd64) arch=amd64   ;;
				arm64) arch=arm64   ;;
				armhf) arch=armv6l  ;;
				armel) arch=armv6l  ;;
				*) die "unsupported architecture for the Go tarball" ;;
			esac
			rm -rf /usr/local/go
			curl -fsSL "https://go.dev/dl/go${GO_VERSION}.linux-${arch}.tar.gz" \
				| tar -C /usr/local -xz
			export PATH="/usr/local/go/bin:$PATH"
		fi
	fi
	log "building aircoind (pure-Go sqlite; no CGO)..."
	mkdir -p /tmp/aircoins-build
	if ! (cd "$SRC/go" && GOFLAGS=-mod=mod go build -o /tmp/aircoins-build/aircoind .); then
		die "build failed — on small SBCs this is usually RAM; cross-compile on a PC and retry with --prebuilt"
	fi
	install -m 0755 /tmp/aircoins-build/aircoind "$BIN"
	rm -rf /tmp/aircoins-build
	log "installed $BIN"
}

if [ -n "$PREBUILT" ]; then
	[ -x "$PREBUILT" ] || die "prebuilt binary $PREBUILT not found/executable"
	# `install` refuses src == dst; passing the live binary as its own
	# prebuilt is a legitimate way to skip the rebuild on slow boards.
	if [ "$(readlink -f "$PREBUILT")" != "$(readlink -f "$BIN")" ]; then
		install -m 0755 "$PREBUILT" "$BIN"
	fi
	log "installed prebuilt $BIN"
else
	build_binary
fi

# ----------------------------------------------------------------- static --

log "deploying portal + admin static files..."
install -d -m 0755 "$PORTAL_ROOT" "$ADMIN_ROOT"
cp -r "$SRC/portal/."    "$PORTAL_ROOT/"
cp -r "$SRC/admin/."     "$ADMIN_ROOT/"
find "$PORTAL_ROOT" "$ADMIN_ROOT" -type d -exec chmod 0755 {} +
find "$PORTAL_ROOT" "$ADMIN_ROOT" -type f -exec chmod 0644 {} +

# --------------------------------------------------------------- prompts ---

if [ "$ASSUME_YES" = 0 ]; then
	[ -n "$ADMIN_PASS" ] || { ask ADMIN_PASS "Admin panel password for '$ADMIN_USER'" ""; }
	while [ -z "$ADMIN_PASS" ] || [ "${#ADMIN_PASS}" -lt 8 ]; do
		warn "password must be at least 8 characters"
		ask ADMIN_PASS "Admin panel password for '$ADMIN_USER'" ""
	done
	ask ROUTER_IP "Router IP as FreeRADIUS sees it (its SOURCE address)" "$ROUTER_IP"
	if [ -z "$RADIUS_SECRET" ]; then
		RADIUS_SECRET="$(rand_str 24)"
		log "generated RADIUS secret: $RADIUS_SECRET"
	fi
	ask ROUTER_USER "Router REST username (optional, for the panel)" "$ROUTER_USER"
	[ -n "$ROUTER_USER" ] && { read -r -s -p "Router REST password: " ROUTER_PASS; echo; }
else
	[ -n "$ADMIN_PASS" ] || ADMIN_PASS="$(rand_str 16)"
	[ -n "$RADIUS_SECRET" ] || RADIUS_SECRET="$(rand_str 24)"
fi
LOCAL_SECRET="$(rand_str 24)"

# ---------------------------------------------------------------- admin ----

log "creating admin account '$ADMIN_USER' (this also creates the app DB)..."
su -s /bin/bash aircoins -c "$BIN -admin '$ADMIN_USER:$ADMIN_PASS' -data '$DATA_DIR'"

# Seed panel settings so the SPA is ready on first login (never overwrites).
sqlite3 "$DATA_DIR/aircoins.db" <<SQL
INSERT OR IGNORE INTO settings (key, value) VALUES ('portal_name', '$PORTAL_NAME');
INSERT OR IGNORE INTO settings (key, value) VALUES ('sbc_url', '$SBC_URL');
INSERT OR IGNORE INTO settings (key, value) VALUES ('router_url', 'http://$ROUTER_IP');
INSERT OR IGNORE INTO settings (key, value) VALUES ('radius_secret', '$RADIUS_SECRET');
INSERT OR IGNORE INTO settings (key, value) VALUES ('sbc_ip', '$LAN_IP');
SQL
[ -n "$ROUTER_USER" ] && sqlite3 "$DATA_DIR/aircoins.db" \
	"INSERT OR IGNORE INTO settings (key, value) VALUES ('router_user', '$ROUTER_USER');"
[ -n "$ROUTER_PASS" ] && sqlite3 "$DATA_DIR/aircoins.db" \
	"INSERT OR IGNORE INTO settings (key, value) VALUES ('router_pass', '$ROUTER_PASS');"

# -------------------------------------------------------------- lighttpd ---

log "configuring lighttpd (single port 80)..."
sed -e "s|@@PORTAL_DOCROOT@@|$PORTAL_ROOT|g" \
	-e "s|@@ADMIN_DOCROOT@@|$ADMIN_ROOT|g" \
	"$SRC/conf/lighttpd/aircoins.conf" > /etc/lighttpd/conf-available/60-aircoins.conf
# comment lines may document token names — only a live setting still holding
# a token is an error
if grep -v '^[[:space:]]*#' /etc/lighttpd/conf-available/60-aircoins.conf | grep -q '@@'; then
	die "unsubstituted tokens in lighttpd config — aborting"
fi
lighty-enable-mod aircoins >/dev/null || true

# ------------------------------------------------------------- freeradius --

FR_ETC="$(ls -d /etc/freeradius/3.* 2>/dev/null | sort -V | tail -1 || true)"
[ -n "$FR_ETC" ] || die "FreeRADIUS config directory not found under /etc/freeradius"
log "configuring FreeRADIUS in $FR_ETC ..."

# sql module: replace entirely (stock defaults to rlm_sql_null / mysql),
# then enable it — activation is the mods-enabled symlink, not a package.
[ -f "$FR_ETC/mods-available/sql" ] && cp -n "$FR_ETC/mods-available/sql" "$FR_ETC/mods-available/sql.aircoins-bak" || true
sed -e "s|@@RADIUS_DB@@|$DATA_DIR/radius.db|g" \
	"$SRC/conf/freeradius/sql" > "$FR_ETC/mods-available/sql"
grep -v '^[[:space:]]*#' "$FR_ETC/mods-available/sql" | grep -q '@@' \
	&& die "unsubstituted tokens in the sql module"
ln -sfn ../mods-available/sql "$FR_ETC/mods-enabled/sql"

# site: our server block replaces default/inner-tunnel.
install -m 0644 "$SRC/conf/freeradius/aircoins" "$FR_ETC/sites-available/aircoins"
rm -f "$FR_ETC/sites-enabled/default" "$FR_ETC/sites-enabled/inner-tunnel"
ln -sfn ../sites-available/aircoins "$FR_ETC/sites-enabled/aircoins"

# The aircoins site only handles PAP/CHAP — no Auth-Type EAP section.
# Remove stock modules that require sections our site does not provide.
rm -f "$FR_ETC/mods-enabled/eap"

# clients.conf: the NAS is keyed by the router's REAL source IP. Get this
# wrong and every login dies as "unknown client" in radius.log.
FRV="$(freeradius -v 2>/dev/null | grep -oE '[0-9]+\.[0-9]+\.[0-9]+' | head -1 || true)"
RMA=""
if [ -n "$FRV" ] && ver_ge "$FRV" "3.0.23"; then
	RMA="	require_message_authenticator = yes"
fi
cat > "$FR_ETC/clients.conf" <<EOF
# Generated by install.sh — the MikroTik router (only real NAS).
client router {
	ipaddr = $ROUTER_IP
	secret = "$RADIUS_SECRET"
	shortname = aircoins-router
$RMA
}

# Loopback client for radtest self-tests only.
client localhost {
	ipaddr = 127.0.0.1
	secret = "$LOCAL_SECRET"
	shortname = aircoins-test
}
EOF

if ! freeradius -C >/dev/null 2>&1; then
	freeradius -C || true
	die "FreeRADIUS config check failed — see the output above"
fi
log "FreeRADIUS config check passed (freeradius $FRV)"

# restart, never just start: on re-runs the service is already active and
# `enable --now` would leave the freshly written config unloaded.
systemctl enable freeradius >/dev/null 2>&1
systemctl restart freeradius
if command -v ufw >/dev/null 2>&1 && ufw status 2>/dev/null | grep -q "Status: active"; then
	ufw allow 80/tcp  >/dev/null || true
	ufw allow 1812:1813/udp >/dev/null || true
	log "ufw: allowed 80/tcp and 1812-1813/udp"
fi

# ---------------------------------------------------------------- systemd --

log "installing aircoind systemd service..."
cat > "$UNIT" <<EOF
[Unit]
Description=AIRCOINS NETFI backend (aircoind)
After=network-online.target
Wants=network-online.target

[Service]
User=aircoins
Group=aircoins
UMask=0002
ExecStart=$BIN -listen $LISTEN -data $DATA_DIR
Restart=always
RestartSec=3
NoNewPrivileges=yes
ProtectSystem=strict
ReadWritePaths=$DATA_DIR
PrivateTmp=yes
ProtectHome=yes

[Install]
WantedBy=multi-user.target
EOF
systemctl daemon-reload
systemctl enable aircoind >/dev/null 2>&1
systemctl restart aircoind

systemctl restart lighttpd

# --------------------------------------------------------------- selftest --

log "self-test..."
ok=1
sleep 1
if curl -fsS "http://127.0.0.1/api/health" | grep -q '"ok":true'; then
	log "API health: OK"
else
	warn "API health check failed: systemctl status aircoind; journalctl -u aircoind -n 20"
	ok=0
fi
if curl -fsS "http://127.0.0.1/" | grep -qi 'aircoins\|piso'; then
	log "portal page: OK"
else
	warn "portal page check failed"
	ok=0
fi
code="$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1/admin/ || true)"
[ "$code" = "200" ] && log "admin page: OK" || { warn "admin page returned HTTP $code"; ok=0; }

if command -v radtest >/dev/null 2>&1; then
	sqlite3 "$DATA_DIR/radius.db" \
		"INSERT OR REPLACE INTO radcheck (username, attribute, op, value) VALUES ('aircoins-selftest', 'Cleartext-Password', ':=', 'selftest-pass');"
	if radtest aircoins-selftest selftest-pass 127.0.0.1 0 "$LOCAL_SECRET" 2>&1 | grep -q 'Access-Accept'; then
		log "RADIUS auth self-test: Access-Accept"
	else
		warn "radtest did not Accept — check: journalctl -u freeradius -n 30"
		ok=0
	fi
	sqlite3 "$DATA_DIR/radius.db" "DELETE FROM radcheck WHERE username = 'aircoins-selftest';"
else
	warn "radtest not found — skipped the RADIUS self-test"
fi

chown -R aircoins:aircoins "$DATA_DIR"
chmod 664 "$DATA_DIR"/radius.db* 2>/dev/null || true

# ---------------------------------------------------------------- summary --

echo
log "install complete. $( [ $ok -eq 1 ] && echo 'All checks passed.' || echo 'Some checks need attention — see warnings.' )"
echo "  Portal        : http://$LAN_IP/"
echo "  Admin panel   : http://$LAN_IP/admin/   (user: $ADMIN_USER)"
if [ "$ASSUME_YES" = 1 ]; then
	echo "  Admin password: $ADMIN_PASS   (change it after first login)"
fi
echo "  RADIUS secret : $RADIUS_SECRET   (must match the router's /radius entry)"
echo
echo "Next steps:"
echo "  1. Open the admin panel > Settings: set the router REST user/password."
echo "  2. Admin > MikroTik setup: Re-check, then Apply configuration."
echo "  3. Admin > Tools: Upload all stubs (needs sbc_url, seeded as $SBC_URL)."
echo "  4. Print vouchers (Admin > Vouchers) and test a login on the WiFi."
