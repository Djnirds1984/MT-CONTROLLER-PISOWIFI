<?php
/**
 * AIRCOINS NETFI — FreeRADIUS SQLite DBAL.
 *
 * Provides a shared PDO connection to the FreeRADIUS SQLite database and
 * helper functions for managing RADIUS users (radcheck / radreply tables).
 *
 * The FreeRADIUS SQLite schema uses:
 *   radcheck  — authentication attributes (Cleartext-Password, etc.)
 *   radreply  — reply attributes sent in Access-Accept (Session-Timeout, etc.)
 *   radacct   — accounting records (written by FreeRADIUS, read-only for us)
 *   radpostauth — post-auth log (written by FreeRADIUS)
 *
 * No framework, no Composer: plain PHP 8.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

/**
 * Return the singleton PDO connection to the FreeRADIUS SQLite database.
 *
 * Uses WAL mode and a 5-second busy timeout for safe concurrent access
 * between FreeRADIUS (C process) and PHP (admin panel / API).
 *
 * @return PDO Shared PDO instance.
 * @throws PDOException When the database cannot be opened.
 */
function aircoins_radius_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $path = (string) AIRCOINS_RADIUS_DB;
    $dir  = dirname($path);
    if ($dir !== '' && !is_dir($dir)) {
        @mkdir($dir, 0770, true);
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
 * Ensure the FreeRADIUS tables exist.
 *
 * This is a subset of the standard FreeRADIUS SQLite schema. We create only
 * the tables we need for user management. The full schema is normally imported
 * by the install script from FreeRADIUS's schema.sql, but this function allows
 * the PHP code to self-heal if the tables are missing.
 *
 * @param PDO $pdo Connection (normally from aircoins_radius_db()).
 */
function aircoins_radius_schema(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS radcheck (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    username   TEXT NOT NULL,
    attribute  TEXT NOT NULL,
    op         TEXT NOT NULL DEFAULT ':=',
    value      TEXT NOT NULL
)
SQL);

    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_radcheck_username ON radcheck (username)');

    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS radreply (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    username   TEXT NOT NULL,
    attribute  TEXT NOT NULL,
    op         TEXT NOT NULL DEFAULT '=',
    value      TEXT NOT NULL
)
SQL);

    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_radreply_username ON radreply (username)');

    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS radgroupcheck (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    groupname  TEXT NOT NULL,
    attribute  TEXT NOT NULL,
    op         TEXT NOT NULL DEFAULT ':=',
    value      TEXT NOT NULL
)
SQL);

    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS radgroupreply (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    groupname  TEXT NOT NULL,
    attribute  TEXT NOT NULL,
    op         TEXT NOT NULL DEFAULT '=',
    value      TEXT NOT NULL
)
SQL);

    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS radusergroup (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    username   TEXT NOT NULL,
    groupname  TEXT NOT NULL,
    priority   INTEGER NOT NULL DEFAULT 1
)
SQL);

    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS radpostauth (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    username   TEXT NOT NULL,
    pass       TEXT,
    reply      TEXT,
    authdate   TEXT NOT NULL
)
SQL);

    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS radacct (
    radacctid           INTEGER PRIMARY KEY AUTOINCREMENT,
    acctsessionid       TEXT NOT NULL,
    acctuniqueid        TEXT,
    username            TEXT,
    realm               TEXT,
    nasipaddress        TEXT,
    nasportid           TEXT,
    nasporttype         TEXT,
    acctstarttime       TEXT,
    acctupdatetime      TEXT,
    acctstoptime        TEXT,
    acctinterval        INTEGER,
    acctsessiontime     INTEGER,
    acctauthentic       TEXT,
    connectinfo_start   TEXT,
    connectinfo_stop    TEXT,
    acctinputoctets     INTEGER,
    acctoutputoctets    INTEGER,
    calledstationid     TEXT,
    callingstationid    TEXT,
    acctterminatecause  TEXT,
    servicetype         TEXT,
    framedprotocol      TEXT,
    framedipaddress     TEXT
)
SQL);

    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_radacct_username ON radacct (username)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_radacct_session ON radacct (acctsessionid)');
}

/**
 * Add a RADIUS user with cleartext password and optional session timeout.
 *
 * Creates entries in radcheck (Cleartext-Password) and radreply (Session-Timeout).
 * If the user already exists, existing entries are replaced.
 *
 * @param PDO    $pdo            Connection.
 * @param string $username       Username (voucher code or MAC).
 * @param string $password       Cleartext password (empty string for MAC-auth).
 * @param int    $sessionSeconds Session timeout in seconds (0 = no limit).
 * @param string $comment        Optional comment stored as a radcheck attribute.
 * @return int   The new radcheck row ID.
 */
function aircoins_radius_add_user(
    PDO    $pdo,
    string $username,
    string $password,
    int    $sessionSeconds = 0,
    string $comment = ''
): int {
    // Remove any existing entries first (replace semantics)
    aircoins_radius_remove_user($pdo, $username);

    // Insert Cleartext-Password into radcheck
    $stmt = $pdo->prepare(
        'INSERT INTO radcheck (username, attribute, op, value) VALUES (?, \'Cleartext-Password\', \':=\', ?)'
    );
    $stmt->execute([$username, $password]);
    $checkId = (int) $pdo->lastInsertId();

    // Insert Session-Timeout into radreply if specified
    if ($sessionSeconds > 0) {
        $stmt = $pdo->prepare(
            'INSERT INTO radreply (username, attribute, op, value) VALUES (?, \'Session-Timeout\', \'=\', ?)'
        );
        $stmt->execute([$username, (string) $sessionSeconds]);
    }

    // Insert optional comment
    if ($comment !== '') {
        $stmt = $pdo->prepare(
            'INSERT INTO radcheck (username, attribute, op, value) VALUES (?, \'Comment\', \':=\', ?)'
        );
        $stmt->execute([$username, $comment]);
    }

    return $checkId;
}

/**
 * Remove a RADIUS user and all associated check/reply entries.
 *
 * @param PDO    $pdo      Connection.
 * @param string $username Username to remove.
 */
function aircoins_radius_remove_user(PDO $pdo, string $username): void
{
    $stmt = $pdo->prepare('DELETE FROM radcheck WHERE username = ?');
    $stmt->execute([$username]);

    $stmt = $pdo->prepare('DELETE FROM radreply WHERE username = ?');
    $stmt->execute([$username]);

    $stmt = $pdo->prepare('DELETE FROM radusergroup WHERE username = ?');
    $stmt->execute([$username]);
}

/**
 * List all RADIUS users with their password and session timeout.
 *
 * @param  PDO $pdo Connection.
 * @return array    Array of user records: [username, password, session_timeout, comment].
 */
function aircoins_radius_list_users(PDO $pdo): array
{
    $sql = <<<'SQL'
SELECT
    c.username,
    MAX(CASE WHEN c.attribute = 'Cleartext-Password' THEN c.value END) AS password,
    MAX(CASE WHEN c.attribute = 'Comment' THEN c.value END) AS comment,
    MAX(CASE WHEN r.attribute = 'Session-Timeout' THEN r.value END) AS session_timeout
FROM radcheck c
LEFT JOIN radreply r ON r.username = c.username
WHERE c.attribute IN ('Cleartext-Password', 'Comment')
GROUP BY c.username
ORDER BY c.username
SQL;

    return $pdo->query($sql)->fetchAll();
}

/**
 * Find a single RADIUS user by username.
 *
 * @param  PDO    $pdo      Connection.
 * @param  string $username Username to find.
 * @return array|null       User record or null if not found.
 */
function aircoins_radius_find_user(PDO $pdo, string $username): ?array
{
    $sql = <<<'SQL'
SELECT
    c.username,
    MAX(CASE WHEN c.attribute = 'Cleartext-Password' THEN c.value END) AS password,
    MAX(CASE WHEN c.attribute = 'Comment' THEN c.value END) AS comment,
    MAX(CASE WHEN r.attribute = 'Session-Timeout' THEN r.value END) AS session_timeout
FROM radcheck c
LEFT JOIN radreply r ON r.username = c.username
WHERE c.username = ?
GROUP BY c.username
SQL;

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$username]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Update the session timeout for a RADIUS user.
 *
 * If the user has an existing Session-Timeout in radreply, it is updated.
 * If not, a new entry is created.
 *
 * @param PDO    $pdo            Connection.
 * @param string $username       Username.
 * @param int    $sessionSeconds New session timeout in seconds.
 */
function aircoins_radius_update_session(PDO $pdo, string $username, int $sessionSeconds): void
{
    // Check if Session-Timeout already exists
    $stmt = $pdo->prepare(
        'SELECT id FROM radreply WHERE username = ? AND attribute = \'Session-Timeout\''
    );
    $stmt->execute([$username]);
    $existing = $stmt->fetch();

    if ($existing) {
        // Update existing
        $stmt = $pdo->prepare(
            'UPDATE radreply SET value = ? WHERE username = ? AND attribute = \'Session-Timeout\''
        );
        $stmt->execute([(string) $sessionSeconds, $username]);
    } else {
        // Insert new
        $stmt = $pdo->prepare(
            'INSERT INTO radreply (username, attribute, op, value) VALUES (?, \'Session-Timeout\', \'=\', ?)'
        );
        $stmt->execute([$username, (string) $sessionSeconds]);
    }
}

/**
 * Extend the session timeout for a RADIUS user by adding seconds.
 *
 * Reads the current Session-Timeout, adds the specified seconds, and updates.
 * If no existing timeout, creates one with the specified seconds.
 *
 * @param PDO    $pdo        Connection.
 * @param string $username   Username.
 * @param int    $addSeconds Seconds to add.
 * @return int               New total session timeout in seconds.
 */
function aircoins_radius_extend_session(PDO $pdo, string $username, int $addSeconds): int
{
    $stmt = $pdo->prepare(
        'SELECT value FROM radreply WHERE username = ? AND attribute = \'Session-Timeout\''
    );
    $stmt->execute([$username]);
    $row = $stmt->fetch();

    $current = $row ? (int) $row['value'] : 0;
    $newTotal = $current + $addSeconds;

    aircoins_radius_update_session($pdo, $username, $newTotal);

    return $newTotal;
}

/**
 * Verify RADIUS credentials for a user.
 *
 * This is a PHP-side check (not the actual RADIUS protocol). Useful for
 * custom auth flows or testing.
 *
 * @param  PDO    $pdo      Connection.
 * @param  string $username Username.
 * @param  string $password Password to verify.
 * @return bool             True if credentials match.
 */
function aircoins_radius_auth_check(PDO $pdo, string $username, string $password): bool
{
    $stmt = $pdo->prepare(
        'SELECT value FROM radcheck WHERE username = ? AND attribute = \'Cleartext-Password\''
    );
    $stmt->execute([$username]);
    $row = $stmt->fetch();

    if (!$row) {
        return false;
    }

    return $row['value'] === $password;
}

/**
 * Get active RADIUS accounting sessions.
 *
 * Reads from radacct for sessions that are still open (acctstoptime IS NULL).
 *
 * @param  PDO $pdo Connection.
 * @return array    Array of active session records.
 */
function aircoins_radius_active_sessions(PDO $pdo): array
{
    $sql = <<<'SQL'
SELECT
    radacctid,
    acctsessionid,
    username,
    nasipaddress,
    framedipaddress,
    acctstarttime,
    acctsessiontime,
    acctinputoctets,
    acctoutputoctets,
    callingstationid
FROM radacct
WHERE acctstoptime IS NULL
ORDER BY acctstarttime DESC
SQL;

    return $pdo->query($sql)->fetchAll();
}

/**
 * Convert minutes to a MikroTik-compatible time string.
 *
 * @param  int    $minutes Minutes to convert.
 * @return string          Time string (e.g. "15m", "1h30m", "2h").
 */
function aircoins_minutes_to_time(int $minutes): string
{
    if ($minutes <= 0) {
        return '0s';
    }
    $hours   = intdiv($minutes, 60);
    $mins    = $minutes % 60;
    $result  = '';
    if ($hours > 0) {
        $result .= $hours . 'h';
    }
    if ($mins > 0) {
        $result .= $mins . 'm';
    }
    return $result;
}
