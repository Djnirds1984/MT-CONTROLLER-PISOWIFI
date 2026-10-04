<?php
/**
 * AIRCOINS NETFI — SQLite persistence layer.
 *
 * Provides a lazily-created singleton PDO connection (WAL, sane busy timeout)
 * and the full database schema. All tables are created with
 * CREATE TABLE IF NOT EXISTS so the schema step is idempotent.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

/**
 * Return the singleton PDO connection to the AIRCOINS SQLite database.
 *
 * The parent directory is created on demand (best-effort). The connection uses
 * exception error mode, associative fetches and native (non-emulated) prepares.
 *
 * @return PDO Shared PDO instance.
 * @throws PDOException When the database cannot be opened.
 */
function aircoins_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $path = (string) AIRCOINS_DB;
    $dir = dirname($path);
    if ($dir !== '' && !is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }

    $pdo = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    $pdo->exec('PRAGMA journal_mode=WAL');
    $pdo->exec('PRAGMA busy_timeout=5000');
    $pdo->exec('PRAGMA synchronous=NORMAL');
    $pdo->exec('PRAGMA foreign_keys=ON');

    return $pdo;
}

/**
 * Create the AIRCOINS schema (idempotent) on the given connection.
 *
 * @param PDO $pdo Target connection (normally from aircoins_db()).
 * @throws PDOException On any DDL failure.
 */
function aircoins_schema(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS admins (
    id        INTEGER PRIMARY KEY AUTOINCREMENT,
    username  TEXT UNIQUE NOT NULL,
    pass_hash TEXT NOT NULL,
    created_at INTEGER
)
SQL);

    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS routers (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    name        TEXT NOT NULL,
    host        TEXT NOT NULL,
    api_type    TEXT NOT NULL CHECK(api_type IN ('rest','legacy')),
    api_port    INTEGER NOT NULL,
    username    TEXT NOT NULL,
    pass_enc    TEXT NOT NULL,
    tls_verify  INTEGER DEFAULT 0,
    disabled    INTEGER DEFAULT 0,
    last_status TEXT,
    last_error  TEXT,
    created_at  INTEGER
)
SQL);

    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS login_attempts (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    ip           TEXT NOT NULL,
    attempted_at INTEGER NOT NULL
)
SQL);

    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS audit_log (
    id       INTEGER PRIMARY KEY AUTOINCREMENT,
    admin_id INTEGER,
    action   TEXT NOT NULL,
    detail   TEXT,
    ip       TEXT,
    ts       INTEGER
)
SQL);

    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS monitor_samples (
    id        INTEGER PRIMARY KEY AUTOINCREMENT,
    router_id INTEGER NOT NULL,
    iface     TEXT NOT NULL,
    rx_byte   INTEGER,
    tx_byte   INTEGER,
    ts        INTEGER
)
SQL);

    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_login_attempts_ip_ts ON login_attempts (ip, attempted_at)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_monitor_samples_router_iface_ts ON monitor_samples (router_id, iface, ts)');

    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS devices (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    mac_address  TEXT NOT NULL,
    ip_address   TEXT,
    hostname     TEXT,
    user         TEXT,
    router_id    INTEGER,
    session_time TEXT,
    status       TEXT DEFAULT 'active' CHECK(status IN ('active','expired','blocked')),
    first_seen   INTEGER,
    last_seen    INTEGER,
    created_at   INTEGER,
    updated_at   INTEGER
)
SQL);

    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_devices_mac ON devices (mac_address)');

    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS voucher_log (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    code       TEXT NOT NULL,
    mac        TEXT,
    ip         TEXT,
    router_id  INTEGER,
    used_at    INTEGER,
    expires_at INTEGER
)
SQL);

    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_voucher_log_code ON voucher_log (code)');

    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS vendo_devices (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    mac_address    TEXT UNIQUE NOT NULL,
    ip_address     TEXT,
    hostname       TEXT,
    router_id      INTEGER,
    status         TEXT DEFAULT 'pending' CHECK(status IN ('pending','accepted','disabled')),
    assigned_ip    TEXT,
    coin_pin          INTEGER DEFAULT 4,
    debounce_ms       INTEGER DEFAULT 150,
    minutes_per_pulse INTEGER DEFAULT 15,
    device_name       TEXT,
    accepted_at    INTEGER,
    last_seen      INTEGER,
    created_at     INTEGER
)
SQL);

    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_vendo_devices_status ON vendo_devices (status)');

    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS vendo_rates (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    vendo_id    INTEGER NOT NULL,
    coins       INTEGER NOT NULL DEFAULT 1,
    time_value  INTEGER NOT NULL DEFAULT 15,
    time_unit   TEXT    NOT NULL DEFAULT 'MIN' CHECK(time_unit IN ('MIN','HRS')),
    FOREIGN KEY (vendo_id) REFERENCES vendo_devices(id) ON DELETE CASCADE
)
SQL);

    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_vendo_rates_vendo ON vendo_rates (vendo_id)');

    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS settings (
    key   TEXT PRIMARY KEY,
    value TEXT NOT NULL DEFAULT ''
)
SQL);

    // Migration: add new columns to existing vendo_devices tables that were
    // created before the card-settings refactor.
    $cols = [];
    try {
        $colRows = $pdo->query("PRAGMA table_info(vendo_devices)")->fetchAll();
        foreach ($colRows as $cr) {
            $cols[] = (string) ($cr['name'] ?? '');
        }
    } catch (Throwable $e) { /* table may not exist yet */ }

    $migrations = [
        'coin_pin'       => "ALTER TABLE vendo_devices ADD COLUMN coin_pin INTEGER DEFAULT 4",
        'debounce_ms'    => "ALTER TABLE vendo_devices ADD COLUMN debounce_ms INTEGER DEFAULT 150",
        'minutes_per_pulse' => "ALTER TABLE vendo_devices ADD COLUMN minutes_per_pulse INTEGER DEFAULT 15",
        'device_name'    => "ALTER TABLE vendo_devices ADD COLUMN device_name TEXT DEFAULT ''",
    ];
    foreach ($migrations as $colName => $ddl) {
        if (!in_array($colName, $cols, true)) {
            try { $pdo->exec($ddl); } catch (Throwable $e) { /* ignore */ }
        }
    }
}

/**
 * Read a single setting value by key. Returns $default when missing.
 *
 * @param PDO    $pdo     Database connection.
 * @param string $key     Setting key.
 * @param string $default Fallback value.
 * @return string
 */
function aircoins_get_setting(PDO $pdo, string $key, string $default = ''): string
{
    try {
        $stmt = $pdo->prepare('SELECT value FROM settings WHERE key = :k');
        $stmt->execute([':k' => $key]);
        $row = $stmt->fetch();
        return $row ? (string) $row['value'] : $default;
    } catch (Throwable $e) {
        return $default;
    }
}

/**
 * Write a setting (insert or update).
 *
 * @param PDO    $pdo   Database connection.
 * @param string $key   Setting key.
 * @param string $value Setting value.
 */
function aircoins_set_setting(PDO $pdo, string $key, string $value): void
{
    $pdo->prepare(
        'INSERT INTO settings (key, value) VALUES (:k, :v)'
        . ' ON CONFLICT(key) DO UPDATE SET value = excluded.value'
    )->execute([':k' => $key, ':v' => $value]);
}
