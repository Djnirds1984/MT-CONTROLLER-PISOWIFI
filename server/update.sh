#!/usr/bin/env bash
# ============================================================================
# AIRCOINS NETFI — updater
#
# Re-syncs the portal/admin static files and replaces the aircoind binary.
# Data (databases, settings, vouchers) is NEVER touched. Run install.sh
# instead when FreeRADIUS/lighttpd configuration or the data layout changed.
#
# Usage: sudo bash update.sh [--prebuilt FILE]
# ============================================================================
set -euo pipefail

SRC="$(cd "$(dirname "$0")" && pwd)"
DATA_DIR="/var/lib/aircoins"
PORTAL_ROOT="/var/www/aircoins/portal"
ADMIN_ROOT="/var/www/aircoins/admin"
BIN="/usr/local/bin/aircoind"
PREBUILT=""

log()  { printf '\033[1;36m[aircoins]\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m[warn]\033[0m %s\n' "$*"; }
die()  { printf '\033[1;31m[error]\033[0m %s\n' "$*" >&2; exit 1; }

while [ $# -gt 0 ]; do
	case "$1" in
		--prebuilt) PREBUILT="$2"; shift 2 ;;
		-h|--help)  sed -n '2,10p' "$0"; exit 0 ;;
		*) die "unknown option: $1" ;;
	esac
done

[ "$(id -u)" -eq 0 ] || die "run as root: sudo bash $0"
[ -d "$PORTAL_ROOT" ] || die "no previous install found ($PORTAL_ROOT missing) — run install.sh first"

# ----------------------------------------------------------------- static --

log "syncing portal + admin static files..."
mkdir -p "$PORTAL_ROOT" "$ADMIN_ROOT"
cp -r "$SRC/portal/." "$PORTAL_ROOT/"
cp -r "$SRC/admin/."  "$ADMIN_ROOT/"
find "$PORTAL_ROOT" "$ADMIN_ROOT" -type d -exec chmod 0755 {} +
find "$PORTAL_ROOT" "$ADMIN_ROOT" -type f -exec chmod 0644 {} +

# ---------------------------------------------------------------- binary ---

if [ -n "$PREBUILT" ]; then
	[ -x "$PREBUILT" ] || die "prebuilt binary $PREBUILT not found/executable"
	install -m 0755 "$PREBUILT" "$BIN"
	log "replaced $BIN (prebuilt)"
else
	command -v go >/dev/null 2>&1 || [ -x /usr/local/go/bin/go ] || \
		die "no Go toolchain — pass --prebuilt /path/to/aircoind"
	[ -x /usr/local/go/bin/go ] && export PATH="/usr/local/go/bin:$PATH"
	log "rebuilding aircoind..."
	mkdir -p /tmp/aircoins-build
	(cd "$SRC/go" && go build -o /tmp/aircoins-build/aircoind .) \
		|| die "build failed — retry with --prebuilt"
	install -m 0755 /tmp/aircoins-build/aircoind "$BIN"
	rm -rf /tmp/aircoins-build
	log "replaced $BIN"
fi

# -------------------------------------------------------------- services ---

systemctl restart aircoind
systemctl reload lighttpd 2>/dev/null || systemctl restart lighttpd
sleep 1
if curl -fsS http://127.0.0.1/api/health | grep -q '"ok":true'; then
	log "update complete — API healthy, data untouched."
else
	warn "health check failed: journalctl -u aircoind -n 20"
	exit 1
fi
