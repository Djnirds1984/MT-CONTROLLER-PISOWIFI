#!/usr/bin/env bash
# ============================================================================
#  AIRCOINS NETFI — quick portal / code re-sync
#  File: deploy/scripts/update-portal.sh
# ----------------------------------------------------------------------------
#  Re-copies the portal HTML + admin + includes + api from a SOURCE repo into
#  the deployed directories WITHOUT re-running the full installer. Useful after
#  you edit portal pages, admin UI or PHP core on your workstation and push the
#  repo to the SBC.
#
#  Behaviour:
#    * The portal (static HTML/CSS/JS) is re-synced; lighttpd serves it straight
#      from disk, so NO service restart is needed for portal-only changes.
#    * If anything under includes/, admin/ or api/ changed, php-fpm is RELOADED
#      (clears OPcache so edited PHP takes effect immediately).
#
#  Usage:
#      sudo ./update-portal.sh [SOURCE_REPO_DIR]
#  SOURCE_REPO_DIR defaults to the repo this script lives in (../..).
# ============================================================================

set -euo pipefail
export LC_ALL=C

if [ -t 1 ]; then
    C_RESET=$'\033[0m'; C_BOLD=$'\033[1m'; C_RED=$'\033[31m'
    C_GREEN=$'\033[32m'; C_YELLOW=$'\033[33m'; C_BLUE=$'\033[34m'
else
    C_RESET=''; C_BOLD=''; C_RED=''; C_GREEN=''; C_YELLOW=''; C_BLUE=''
fi
info() { printf '%s[*]%s %s\n' "$C_BLUE"   "$C_RESET" "$*"; }
ok()   { printf '%s[ok]%s %s\n' "$C_GREEN" "$C_RESET" "$*"; }
warn() { printf '%s[!]%s %s\n' "$C_YELLOW" "$C_RESET" "$*" >&2; }
err()  { printf '%s[x]%s %s\n' "$C_RED"   "$C_RESET" "$*" >&2; }
die()  { err "$*"; exit 1; }

# ----------------------------------------------------------------------------
# Paths (must match install-sbc.sh)
# ----------------------------------------------------------------------------
WWW="/var/www/aircoins"
PORTAL="$WWW/portal"
ADMIN="$WWW/app/admin"
INCLUDES="$WWW/app/includes"
API="$WWW/app/api"

if [ "$(id -u)" -ne 0 ]; then
    die "Run as root: sudo $0 ${1:-}"
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SRC="${AIRCOINS_SRC:-$(cd "$SCRIPT_DIR/../.." && pwd)}"
if [ "${1:-}" != "" ]; then
    SRC="$(cd "$1" && pwd)"
fi
for d in hotspot admin includes api; do
    [ -d "$SRC/$d" ] || die "Source repo '$SRC' is missing '$d/'."
done
[ -d "$WWW" ] || die "Deployment dir '$WWW' not found — run install-sbc.sh first."

PHP_CHANGED=0

# Copy a directory tree, optionally flagging PHP_CHANGED when content differs.
#   $1 = source dir, $2 = dest dir, $3 = 1 to track changes (PHP dirs), 0 static
sync_dir() {
    local src="$1" dst="$2" track="${3:-0}"
    case "$dst" in
        "$WWW"|"$WWW"/*) : ;;
        *) die "refusing to write outside $WWW (got: $dst)" ;;
    esac
    mkdir -p "$dst"
    if command -v rsync >/dev/null 2>&1; then
        if [ "$track" = "1" ]; then
            # Dry-run itemize: any file transfer line ('<' or '>') means a change.
            if rsync -rlptgoD --delete -n -i "$src"/ "$dst"/ | grep -qE '^[<>]'; then
                PHP_CHANGED=1
            fi
        fi
        rsync -a --delete "$src"/ "$dst"/
    else
        [ "$track" = "1" ] && PHP_CHANGED=1   # cp can't cheaply diff; assume change
        find "$dst" -mindepth 1 -maxdepth 1 -exec rm -rf {} +
        cp -a "$src"/. "$dst"/
    fi
}

info "Re-syncing from: $SRC"
sync_dir "$SRC/hotspot"  "$PORTAL"   0
info "portal (static) synced."
sync_dir "$SRC/includes" "$INCLUDES" 1
sync_dir "$SRC/admin"    "$ADMIN"    1
sync_dir "$SRC/api"      "$API"      1
info "includes / admin / api synced."

# Fix ownership + permissions.
chown -R www-data:www-data "$WWW"
find "$WWW" -type d -exec chmod 755 {} +
find "$WWW" -type f -exec chmod 644 {} +
ok "Permissions fixed."

# Reload php-fpm only when PHP code changed (portal-only edits need nothing).
if [ "$PHP_CHANGED" = "1" ]; then
    FPMVER="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null || true)"
    if [ -n "${FPMVER}" ] && systemctl list-unit-files 2>/dev/null | grep -q "^php${FPMVER}-fpm\.service"; then
        info "PHP changed — reloading php${FPMVER}-fpm (clears OPcache) ..."
        if ! systemctl reload "php${FPMVER}-fpm" 2>/dev/null; then
            systemctl restart "php${FPMVER}-fpm"
        fi
        ok "php-fpm reloaded."
    else
        warn "Could not determine php-fpm service; reload it manually if PHP changed."
    fi
else
    info "No PHP changes detected — nothing to reload (static portal already live)."
fi

ok "Update complete."
