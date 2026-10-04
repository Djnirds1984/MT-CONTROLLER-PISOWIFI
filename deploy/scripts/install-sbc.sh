#!/usr/bin/env bash
# ============================================================================
#  AIRCOINS NETFI — SBC installer
#  File: deploy/scripts/install-sbc.sh
# ----------------------------------------------------------------------------
#  Provisions an Ubuntu / Debian / Armbian single-board computer to host:
#    * the captive portal on port 80  (lighttpd, static HTML from hotspot/)
#    * the admin panel  on port 443  (lighttpd + php-fpm, self-signed TLS)
#    * the portal session API        (/api/session.php on port 80)
#
#  Idempotent: safe to re-run. Detects architecture, OS and PHP version.
#
#  Usage:
#      sudo ./install-sbc.sh [SOURCE_REPO_DIR]
#  SOURCE_REPO_DIR defaults to the repo this script lives in (../..).
#  Override with the AIRCOINS_SRC env var if you like.
#
#  Deployed layout (admin/, includes/, api/ MUST be siblings so the PHP
#  relative requires ../includes and ../../includes resolve):
#      /var/www/aircoins/portal        <- hotspot/   (port 80 docroot)
#      /var/www/aircoins/app/admin     <- admin/     (port 443 docroot)
#      /var/www/aircoins/app/includes  <- includes/  (shared PHP core)
#      /var/www/aircoins/app/api       <- api/       (session.php)
# ============================================================================

set -euo pipefail
export LC_ALL=C
export DEBIAN_FRONTEND=noninteractive

# ----------------------------------------------------------------------------
# Colored output (disabled when not a TTY, e.g. piped to a log)
# ----------------------------------------------------------------------------
if [ -t 1 ]; then
    C_RESET=$'\033[0m'; C_BOLD=$'\033[1m'; C_RED=$'\033[31m'
    C_GREEN=$'\033[32m'; C_YELLOW=$'\033[33m'; C_BLUE=$'\033[34m'
else
    C_RESET=''; C_BOLD=''; C_RED=''; C_GREEN=''; C_YELLOW=''; C_BLUE=''
fi
info() { printf '%s[*]%s %s\n'   "$C_BLUE"   "$C_RESET" "$*"; }
ok()   { printf '%s[ok]%s %s\n'  "$C_GREEN"  "$C_RESET" "$*"; }
warn() { printf '%s[!]%s %s\n'   "$C_YELLOW" "$C_RESET" "$*" >&2; }
err()  { printf '%s[x]%s %s\n'   "$C_RED"    "$C_RESET" "$*" >&2; }
die()  { err "$*"; exit 1; }
step() { printf '\n%s%s== %s ==%s\n' "$C_BOLD" "$C_BLUE" "$*" "$C_RESET"; }

# ----------------------------------------------------------------------------
# Fixed deployment paths (single source of truth)
# ----------------------------------------------------------------------------
WWW="/var/www/aircoins"
PORTAL="$WWW/portal"
APP="$WWW/app"
ADMIN="$APP/admin"
INCLUDES="$APP/includes"
API="$APP/api"
DB_DIR="/var/lib/aircoins"
KEY_DIR="/etc/aircoins"
CERT_DIR="/etc/lighttpd/certs"
LIGHTTPD_MAIN="/etc/lighttpd/lighttpd.conf"
UPLOAD_DIR="/var/cache/lighttpd/uploads"
FPM_ERRLOG="/var/log/php-fpm-aircoins.log"

# ----------------------------------------------------------------------------
# Step 0 — root check + locate source repo
# ----------------------------------------------------------------------------
if [ "$(id -u)" -ne 0 ]; then
    die "This installer must run as root. Try: sudo $0 ${1:-}"
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SRC="${AIRCOINS_SRC:-$(cd "$SCRIPT_DIR/../.." && pwd)}"
if [ "${1:-}" != "" ]; then
    SRC="$(cd "$1" && pwd)"
fi

for d in hotspot admin includes api; do
    [ -d "$SRC/$d" ] || die "Source repo '$SRC' is missing the '$d/' directory. Pass the repo root as the first argument."
done
for f in deploy/lighttpd/aircoins.conf deploy/php-fpm/aircoins-pool.conf; do
    [ -f "$SRC/$f" ] || die "Source repo '$SRC' is missing '$f'."
done

# Run a command as the www-data user (runuser preferred, sudo fallback).
as_www() {
    if command -v runuser >/dev/null 2>&1; then
        runuser -u www-data -- "$@"
    else
        sudo -u www-data "$@"
    fi
}

# Copy a directory tree from the repo into the deployment (rsync if present,
# otherwise cp -a). Refuses to touch anything outside $WWW.
copy_tree() {
    local src="$1" dst="$2"
    case "$dst" in
        "$WWW"|"$WWW"/*) : ;;
        *) die "copy_tree refuses to write outside $WWW (got: $dst)" ;;
    esac
    mkdir -p "$dst"
    if command -v rsync >/dev/null 2>&1; then
        rsync -a --delete "$src"/ "$dst"/
    else
        find "$dst" -mindepth 1 -maxdepth 1 -exec rm -rf {} +
        cp -a "$src"/. "$dst"/
    fi
}

# ----------------------------------------------------------------------------
# Step 1 — detect architecture, OS and PHP version
# ----------------------------------------------------------------------------
step "1/12  Detecting platform"
ARCH="$(dpkg --print-architecture 2>/dev/null || uname -m)"
info "Architecture: $ARCH"

OS_ID="unknown"; OS_CODENAME="unknown"
if [ -r /etc/os-release ]; then
    # shellcheck disable=SC1091
    . /etc/os-release
    OS_ID="${ID:-unknown}"
    OS_CODENAME="${VERSION_CODENAME:-unknown}"
fi
info "OS: $OS_ID ($OS_CODENAME)"

# Pick the PHP major.minor to install. Prefer an already-installed php, else map
# the distro codename to its default PHP (bookworm=8.2, noble=8.3, ...).
detect_php_hint() {
    if command -v php >/dev/null 2>&1; then
        php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null && return 0
    fi
    case "${OS_CODENAME}" in
        bullseye)      echo "7.4" ;;
        bookworm)      echo "8.2" ;;
        trixie)        echo "8.4" ;;
        focal)         echo "7.4" ;;
        jammy)         echo "8.1" ;;
        noble)         echo "8.3" ;;
        *)             echo "8.2" ;;
    esac
}
PHP_HINT="$(detect_php_hint)"
info "PHP target (hint): $PHP_HINT"

# ----------------------------------------------------------------------------
# Step 2 — apt update + install packages
# ----------------------------------------------------------------------------
step "2/12  Installing packages"
info "apt-get update ..."
apt-get update -y

BASE_PKGS=(lighttpd lighttpd-mod-openssl openssl ufw ca-certificates freeradius freeradius-utils)
info "Installing base packages: ${BASE_PKGS[*]}"
apt-get install -y "${BASE_PKGS[@]}"

# FreeRADIUS SQLite module — package name varies by distro
# Debian/Ubuntu: freeradius-sqlite3; some Armbian builds include it in base freeradius
FR_SQLITE_PKG=""
if apt-cache show freeradius-sqlite3 >/dev/null 2>&1; then
    FR_SQLITE_PKG="freeradius-sqlite3"
elif apt-cache show freeradius-sqlite >/dev/null 2>&1; then
    FR_SQLITE_PKG="freeradius-sqlite"
fi
if [ -n "$FR_SQLITE_PKG" ]; then
    info "Installing FreeRADIUS SQLite module: $FR_SQLITE_PKG"
    apt-get install -y "$FR_SQLITE_PKG"
else
    warn "No separate freeradius-sqlite package found — checking if module is bundled..."
    if [ -f /etc/freeradius/3.0/mods-available/sql ] || [ -f /usr/lib/freeradius/rlm_sql_sqlite.so ]; then
        ok "SQLite module appears to be bundled with freeradius."
    else
        warn "FreeRADIUS SQLite module not found. You may need to install it manually."
    fi
fi

# Versioned PHP packages (php-curl for the REST client, php-mbstring for helpers).
# NOTE: sodium is built into PHP core since 7.2 — no separate package needed.
PHP_PKGS=(
    "php${PHP_HINT}-fpm"
    "php${PHP_HINT}-cli"
    "php${PHP_HINT}-sqlite3"
    "php${PHP_HINT}-curl"
    "php${PHP_HINT}-mbstring"
)
if apt-get install -y "${PHP_PKGS[@]}"; then
    ok "Installed versioned PHP packages (${PHP_HINT})."
else
    warn "Versioned php${PHP_HINT}-* not available; falling back to metapackages."
    apt-get install -y php-fpm php-cli php-sqlite3 php-curl php-mbstring
fi

# Verify sodium extension is available (built into PHP core since 7.2)
if ! php -m 2>/dev/null | grep -qi sodium; then
    info "sodium not detected; trying php-libsodium as fallback ..."
    apt-get install -y php-libsodium 2>/dev/null || true
fi
if ! php -m 2>/dev/null | grep -qi sodium; then
    echo "[!] WARNING: PHP sodium extension not detected."
    echo "    On most systems sodium is built into PHP core (>= 7.2)."
    echo "    If missing, install manually: sudo apt-get install php-libsodium"
    echo "    The admin panel requires sodium for router password encryption."
fi

# Authoritative PHP version now that php-cli is installed.
FPMVER="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
FPM_SOCKET="/run/php/php${FPMVER}-fpm-aircoins.sock"
FPM_SERVICE="php${FPMVER}-fpm"
POOL_DIR="/etc/php/${FPMVER}/fpm/pool.d"
ok "Detected PHP ${FPMVER}; fpm service '${FPM_SERVICE}'; socket '${FPM_SOCKET}'."

# ----------------------------------------------------------------------------
# Step 3 — disable conflicting web servers
# ----------------------------------------------------------------------------
step "3/12  Disabling conflicting web servers"
for svc in apache2 nginx; do
    if systemctl list-unit-files 2>/dev/null | grep -q "^${svc}\.service"; then
        warn "Found ${svc}; disabling + stopping (it would grab port 80)."
        systemctl disable --now "${svc}" 2>/dev/null || true
    else
        info "${svc} not present — nothing to disable."
    fi
done

# ----------------------------------------------------------------------------
# Step 4 — create the deployed directory layout and copy the code
# ----------------------------------------------------------------------------
step "4/12  Deploying files into $WWW"
mkdir -p "$PORTAL" "$ADMIN" "$INCLUDES" "$API" "$UPLOAD_DIR"

info "hotspot/   -> $PORTAL"       ; copy_tree "$SRC/hotspot"  "$PORTAL"
info "admin/     -> $ADMIN"         ; copy_tree "$SRC/admin"    "$ADMIN"
info "includes/  -> $INCLUDES"      ; copy_tree "$SRC/includes" "$INCLUDES"
info "api/       -> $API"           ; copy_tree "$SRC/api"      "$API"

# Ownership + sane permissions (dirs 755, files 644; PHP is read, not executed).
chown -R www-data:www-data "$WWW"
find "$WWW" -type d -exec chmod 755 {} +
find "$WWW" -type f -exec chmod 644 {} +
chown www-data:www-data "$UPLOAD_DIR"
chmod 750 "$UPLOAD_DIR"
ok "Files deployed."

# ----------------------------------------------------------------------------
# Step 5 — install lighttpd config + php-fpm pool, restart php-fpm
# ----------------------------------------------------------------------------
step "5/12  Writing web server configuration"

# Back up the stock lighttpd.conf once, then install our self-contained config.
if [ -f "$LIGHTTPD_MAIN" ] && [ ! -f "${LIGHTTPD_MAIN}.aircoins-bak" ]; then
    cp -a "$LIGHTTPD_MAIN" "${LIGHTTPD_MAIN}.aircoins-bak"
    info "Backed up stock config -> ${LIGHTTPD_MAIN}.aircoins-bak"
fi
sed -e "s#@@PORTAL_DOCROOT@@#${PORTAL}#g" \
    -e "s#@@ADMIN_DOCROOT@@#${ADMIN}#g" \
    -e "s#@@API_DIR@@#${API}#g" \
    -e "s#@@FPM_SOCKET@@#${FPM_SOCKET}#g" \
    "$SRC/deploy/lighttpd/aircoins.conf" > "$LIGHTTPD_MAIN"
ok "Installed $LIGHTTPD_MAIN"

# php-fpm pool (dedicated aircoins socket).
mkdir -p "$POOL_DIR"
sed -e "s#@@FPMVER@@#${FPMVER}#g" \
    "$SRC/deploy/php-fpm/aircoins-pool.conf" > "$POOL_DIR/aircoins.conf"
ok "Installed $POOL_DIR/aircoins.conf"

# Data + session dirs referenced by the pool, and a writable PHP error log.
mkdir -p "$DB_DIR" "$DB_DIR/sessions"
chown -R www-data:www-data "$DB_DIR"
chmod 750 "$DB_DIR"; chmod 700 "$DB_DIR/sessions"
touch "$FPM_ERRLOG"; chown www-data:www-data "$FPM_ERRLOG"; chmod 640 "$FPM_ERRLOG"

info "Restarting ${FPM_SERVICE} ..."
systemctl enable "$FPM_SERVICE" >/dev/null 2>&1 || true
systemctl restart "$FPM_SERVICE"
ok "php-fpm running."

# ----------------------------------------------------------------------------
# Step 6 — self-signed TLS certificate for the admin site (port 443)
# ----------------------------------------------------------------------------
step "6/12  Generating self-signed TLS certificate"
CERT_PEM="$CERT_DIR/aircoins.pem"
if [ -f "$CERT_PEM" ]; then
    info "Certificate already exists at $CERT_PEM — keeping it (delete to regenerate)."
else
    mkdir -p "$CERT_DIR"
    CN="$(hostname -f 2>/dev/null || hostname 2>/dev/null || echo aircoins.local)"
    info "Creating certificate for CN=${CN} (valid 10 years) ..."
    openssl req -x509 -nodes -days 3650 -newkey rsa:2048 \
        -keyout "$CERT_DIR/aircoins.key" -out "$CERT_DIR/aircoins.crt" \
        -subj "/C=US/ST=Local/L=Local/O=AIRCOINS NETFI/CN=${CN}" >/dev/null 2>&1
    cat "$CERT_DIR/aircoins.crt" "$CERT_DIR/aircoins.key" > "$CERT_PEM"
    chmod 600 "$CERT_PEM" "$CERT_DIR/aircoins.key" "$CERT_DIR/aircoins.crt"
    ok "Certificate written to $CERT_PEM"
fi

# ----------------------------------------------------------------------------
# Step 7 — libsodium at-rest encryption key (outside the web root)
# ----------------------------------------------------------------------------
step "7/12  Creating sodium key at $KEY_DIR/secret.key"
mkdir -p "$KEY_DIR"
chown www-data:www-data "$KEY_DIR"
chmod 700 "$KEY_DIR"
if [ -f "$KEY_DIR/secret.key" ]; then
    info "Key already exists — keeping it (rotating it would make stored router passwords unreadable)."
else
    # Stored base64 (44 chars) — includes/crypto.php accepts raw/hex/base64.
    as_www php -r 'echo base64_encode(sodium_crypto_secretbox_keygen());' > "$KEY_DIR/secret.key"
    chown www-data:www-data "$KEY_DIR/secret.key"
    chmod 400 "$KEY_DIR/secret.key"
    ok "Generated new 32-byte sodium key (0400 www-data)."
fi

# ----------------------------------------------------------------------------
# Step 8 — initialize the SQLite schema + create the first admin user
# ----------------------------------------------------------------------------
step "8/12  Initializing database + admin user"

INIT_PHP="$(mktemp /tmp/aircoins-init-XXXXXX.php)"
cat > "$INIT_PHP" <<'PHP'
<?php
require '__INC__/db.php';
require '__INC__/auth.php';
$pdo = aircoins_db();
aircoins_schema($pdo);
$n = (int) $pdo->query('SELECT COUNT(*) FROM admins')->fetchColumn();
echo $n;
PHP
sed -i "s#__INC__#${INCLUDES}#g" "$INIT_PHP"
chmod 644 "$INIT_PHP"
ADMIN_COUNT="$(as_www php "$INIT_PHP" 2>/dev/null || echo 0)"
rm -f "$INIT_PHP"
ok "Schema ready (DB: ${DB_DIR}/aircoins.db). Existing admins: ${ADMIN_COUNT}"

if [ "${ADMIN_COUNT:-0}" -eq 0 ]; then
    if [ -t 0 ]; then
        CREATE_PHP="$(mktemp /tmp/aircoins-admin-XXXXXX.php)"
        cat > "$CREATE_PHP" <<'PHP'
<?php
require '__INC__/db.php';
require '__INC__/auth.php';
$u = trim((string) fgets(STDIN));
$p = rtrim((string) fgets(STDIN), "\r\n");
if ($u === '' || $p === '') { fwrite(STDERR, "empty username or password\n"); exit(1); }
$pdo = aircoins_db();
aircoins_schema($pdo);
$st = $pdo->prepare('INSERT INTO admins (username, pass_hash, created_at) VALUES (:u, :h, :t)');
$st->execute([':u' => $u, ':h' => aircoins_hash($p), ':t' => time()]);
fwrite(STDERR, "admin '{$u}' created (argon2id)\n");
PHP
        sed -i "s#__INC__#${INCLUDES}#g" "$CREATE_PHP"
        chmod 644 "$CREATE_PHP"

        printf '%sCreate the first admin account for the panel:%s\n' "$C_BOLD" "$C_RESET"
        ADMIN_USER=""
        read -rp "  Admin username: " ADMIN_USER
        ADMIN_PASS=""; ADMIN_PASS2=""
        read -rsp "  Admin password: " ADMIN_PASS; echo
        read -rsp "  Confirm password: " ADMIN_PASS2; echo
        if [ "$ADMIN_PASS" != "$ADMIN_PASS2" ]; then
            die "Passwords do not match. Re-run the installer to create the admin."
        fi
        if [ -z "$ADMIN_USER" ] || [ -z "$ADMIN_PASS" ]; then
            die "Username and password are required. Re-run to create the admin."
        fi
        printf '%s\n%s\n' "$ADMIN_USER" "$ADMIN_PASS" | as_www php "$CREATE_PHP"
        rm -f "$CREATE_PHP"
        unset ADMIN_PASS ADMIN_PASS2
        ok "Admin user created."
    else
        warn "No TTY and no admins exist. Create one later with:"
        warn "  sudo -u www-data php -r 'require \"${INCLUDES}/db.php\"; require \"${INCLUDES}/auth.php\";"
        warn "    \$p=aircoins_db(); aircoins_schema(\$p); \$p->prepare(\"INSERT INTO admins(username,pass_hash,created_at) VALUES(?,?,?)\")"
        warn "    ->execute([\"admin\", aircoins_hash(\"CHANGE_ME\"), time()]);'"
    fi
else
    info "Admin(s) already present — skipping creation (idempotent)."
fi

# ----------------------------------------------------------------------------
# Step 9 — configure FreeRADIUS with SQLite backend
# ----------------------------------------------------------------------------
step "9/12  Configuring FreeRADIUS (SQLite backend)"

RADIUS_DB="${DB_DIR}/radius.db"
FR_DIR="/etc/freeradius/3.0"

if [ -d "$FR_DIR" ]; then
    # Ensure the RADIUS DB directory exists with correct ownership.
    # IMPORTANT: keep the parent dir as www-data so PHP-FPM can access
    # sessions/ and aircoins.db. Only radius.db itself is owned by freerad.
    mkdir -p "$DB_DIR" "$DB_DIR/sessions"
    chown -R www-data:www-data "$DB_DIR"
    chmod 750 "$DB_DIR"; chmod 700 "$DB_DIR/sessions"

    # --- Configure the SQL module for SQLite ---
    SQL_CONF="$FR_DIR/mods-enabled/sql"
    if [ -f "$SQL_CONF" ] || [ -L "$SQL_CONF" ]; then
        info "Configuring FreeRADIUS SQL module for SQLite ..."
        # Replace the driver, dialect, and connection info
        sed -i 's|^\(\s*driver\s*=\s*\)".*"|\1"sqlite"|' "$FR_DIR/mods-available/sql"
        sed -i 's|^\(\s*dialect\s*=\s*\)".*"|\1"sqlite"|' "$FR_DIR/mods-available/sql"
        sed -i 's|^\(\s*server\s*=\s*\)".*"|\1"localhost"|' "$FR_DIR/mods-available/sql"
        # Set the connection string to point at our RADIUS DB
        sed -i "s|^\(\s*sql\.sqlite\.connect_info\s*=\s*\)\".*\"|\1\"dbdir=${DB_DIR}\"|" "$FR_DIR/mods-available/sql" 2>/dev/null || true
        # If the above pattern didn't match, try the simpler filename pattern
        grep -q "dbdir=${DB_DIR}" "$FR_DIR/mods-available/sql" 2>/dev/null || \
            sed -i "s|\"dbdir=.*\"|\"dbdir=${DB_DIR}\"|" "$FR_DIR/mods-available/sql" 2>/dev/null || true

        # Ensure the pool section is enabled (uncommented)
        # FreeRADIUS ships with pool { ... } commented out for sqlite
        sed -i '/^\s*#\s*pool\s*{/,/^\s*#\s*}/ s/^\s*#\s*//' "$FR_DIR/mods-available/sql" 2>/dev/null || true

        # Enable the sql module
        if [ ! -e "$SQL_CONF" ]; then
            ln -sf ../mods-available/sql "$SQL_CONF"
        fi
        ok "SQL module configured and enabled."
    else
        warn "FreeRADIUS SQL module not found at $SQL_CONF — configure manually."
    fi

    # --- Configure the RADIUS client (MikroTik router) ---
    CLIENTS_CONF="$FR_DIR/clients.conf"
    if [ -f "$CLIENTS_CONF" ]; then
        # Add our MikroTik client if not already present
        if ! grep -q "AIRCOINS" "$CLIENTS_CONF" 2>/dev/null; then
            # Default MikroTik IP; operator MUST edit to match their router (e.g. 10.0.0.1)
            cat >> "$CLIENTS_CONF" <<'CLIENTEOF'

# AIRCOINS NETFI — MikroTik router RADIUS client
# IMPORTANT: Change ipaddr below to your router's actual LAN IP
client router {
    ipaddr = 10.0.0.1
    secret = aircoins_secret
    nastype = other
}
CLIENTEOF
            ok "Added MikroTik RADIUS client to clients.conf. EDIT ipaddr if your router is NOT 10.0.0.1."
        else
            info "AIRCOINS RADIUS client already in clients.conf — keeping it."
        fi
    else
        warn "clients.conf not found at $CLIENTS_CONF — configure the RADIUS client manually."
    fi

    # --- Enable sql in the default site ---
    DEFAULT_SITE="$FR_DIR/sites-enabled/default"
    if [ -f "$DEFAULT_SITE" ]; then
        # Uncomment the sql lines in authorize, accounting, session, post-auth
        sed -i '/^\s*#\s*sql\b/s/^\s*#\s*//' "$DEFAULT_SITE" 2>/dev/null || true
        ok "Enabled sql in default site."
    else
        warn "Default site not found at $DEFAULT_SITE — enable sql manually."
    fi

    # --- Initialize the RADIUS database schema ---
    if [ ! -f "$RADIUS_DB" ] || [ "$(stat -c%s "$RADIUS_DB" 2>/dev/null || echo 0)" -lt 1024 ]; then
        SCHEMA_SQL="/etc/freeradius/3.0/mods-config/sql/main/sqlite/schema.sql"
        if [ -f "$SCHEMA_SQL" ]; then
            info "Importing FreeRADIUS SQLite schema ..."
            sqlite3 "$RADIUS_DB" < "$SCHEMA_SQL" 2>/dev/null || true
            # Both PHP (www-data) and FreeRADIUS (freerad) must read/write this file.
            # Group = www-data, mode 664 => www-data r/w, freerad r/w via group membership.
            usermod -aG freerad www-data 2>/dev/null || true
            chown freerad:www-data "$RADIUS_DB"
            chmod 664 "$RADIUS_DB"
            ok "RADIUS database initialized at $RADIUS_DB"
        else
            warn "Schema file not found at $SCHEMA_SQL — the PHP code will self-heal tables on first use."
        fi
    else
        info "RADIUS database already exists at $RADIUS_DB — keeping it."
    fi

    # --- Restart FreeRADIUS ---
    systemctl enable freeradius >/dev/null 2>&1 || true
    systemctl restart freeradius
    sleep 1
    if systemctl is-active --quiet freeradius; then
        ok "FreeRADIUS is running."
    else
        warn "FreeRADIUS did not start. Check: journalctl -u freeradius -n 40"
        warn "Common fix: ensure the sql module symlink exists: ls -la $FR_DIR/mods-enabled/sql"
    fi
else
    warn "FreeRADIUS config directory $FR_DIR not found — skipping RADIUS setup."
    warn "Install manually: sudo apt-get install freeradius freeradius-sqlite3"
fi

# ----------------------------------------------------------------------------
# Step 10 — firewall (allow 80 + 443 + RADIUS ports). Never auto-enable ufw.
# ----------------------------------------------------------------------------
step "10/12  Configuring firewall"
if command -v ufw >/dev/null 2>&1; then
    ufw allow 80/tcp  >/dev/null 2>&1 || true
    ufw allow 443/tcp >/dev/null 2>&1 || true
    ufw allow 1812/udp >/dev/null 2>&1 || true
    ufw allow 1813/udp >/dev/null 2>&1 || true
    if ufw status 2>/dev/null | grep -qi "Status: active"; then
        ufw reload >/dev/null 2>&1 || true
        ok "ufw active — allowed 80/tcp, 443/tcp, 1812/udp, 1813/udp."
    else
        warn "ufw is inactive; rules added but not enforced. Enable deliberately (allow SSH first): sudo ufw allow OpenSSH && sudo ufw enable"
    fi
else
    warn "ufw not available; skipping firewall configuration."
fi

# ----------------------------------------------------------------------------
# Step 11 — validate lighttpd config, enable + restart, print checklist
# ----------------------------------------------------------------------------
step "11/12  Validating + starting lighttpd"
info "lighttpd -t -f ${LIGHTTPD_MAIN}"
if ! lighttpd -t -f "$LIGHTTPD_MAIN"; then
    die "lighttpd configuration test FAILED. Fix ${LIGHTTPD_MAIN} then re-run."
fi
ok "Configuration OK."

systemctl enable lighttpd >/dev/null 2>&1 || true
systemctl restart lighttpd
sleep 1
if systemctl is-active --quiet lighttpd; then
    ok "lighttpd is running."
else
    warn "lighttpd did not report active. Check: journalctl -u lighttpd -n 40"
fi

SBC_IP="$(hostname -I 2>/dev/null | awk '{print $1}')"
[ -z "${SBC_IP:-}" ] && SBC_IP="<SBC_IP>"

cat <<CHECKLIST

${C_BOLD}${C_GREEN}Installation complete.${C_RESET}

${C_BOLD}Verification checklist${C_RESET}
  1. Portal responds on port 80:
       curl -I  http://localhost/
       curl -s  "http://localhost/api/session.php?mac=00:00:00:00:00:00"
  2. Admin responds on port 443 (self-signed, so use -k):
       curl -kI https://localhost/
  3. Both sockets are listening:
       ss -tlnp | grep -E ':(80|443)\\b'
  4. php-fpm socket exists:
       ls -l ${FPM_SOCKET}
  5. FreeRADIUS is running (UDP 1812):
       ss -ulnp | grep 1812
       systemctl status freeradius
  6. RADIUS database exists:
       ls -la ${RADIUS_DB}
  7. From a hotspot client, browse to:  http://${SBC_IP}/
     Admin panel:                        https://${SBC_IP}/

${C_BOLD}Deployed layout${C_RESET}
  ${PORTAL}            (portal, port 80 docroot)
  ${ADMIN}      (admin, port 443 docroot)
  ${INCLUDES}   (shared PHP core)
  ${API}           (session.php + insertCoin.php, aliased at /api/ on port 80)
  ${DB_DIR}/aircoins.db   (app SQLite)
  ${DB_DIR}/radius.db     (FreeRADIUS SQLite)
  ${KEY_DIR}/secret.key   (sodium key, 0400 www-data)
  ${CERT_PEM}   (self-signed TLS)

Next: configure the MikroTik RADIUS + redirect (see deploy/mikrotik/hotspot-external-portal.rsc).
CHECKLIST
