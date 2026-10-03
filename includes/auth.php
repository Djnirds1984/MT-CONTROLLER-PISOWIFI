<?php
/**
 * AIRCOINS NETFI — authentication, session hardening, rate limiting & audit.
 *
 * Password hashing prefers Argon2id and falls back to bcrypt when the Argon2
 * algorithm is unavailable. Sessions use an httponly, SameSite=Strict cookie
 * that is marked Secure whenever the request arrives over TLS. Login is rate
 * limited per client IP via the login_attempts table, and every privileged
 * action can be written to audit_log through aircoins_audit().
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

/**
 * Hash a password with Argon2id when available, otherwise bcrypt.
 *
 * @param string $password Plaintext password.
 * @return string Stored hash.
 */
function aircoins_hash(string $password): string
{
    $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
    return password_hash($password, $algo);
}

/**
 * Verify a plaintext password against a stored hash.
 *
 * @param string $password Plaintext password.
 * @param string $hash     Stored hash.
 * @return bool True when the password matches.
 */
function aircoins_verify(string $password, string $hash): bool
{
    if ($hash === '') {
        return false;
    }
    return password_verify($password, $hash);
}

/**
 * Whether a stored hash should be re-hashed with current cost/algorithm.
 *
 * Call after a successful aircoins_verify() and persist the new hash when this
 * returns true, so legacy bcrypt hashes migrate to Argon2id transparently.
 *
 * @param string $hash Stored hash.
 * @return bool True when password_hash() would produce a stronger hash.
 */
function aircoins_needs_rehash(string $hash): bool
{
    $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
    return password_needs_rehash($hash, $algo);
}

/**
 * Start the hardened admin session (idempotent).
 *
 * The cookie path is '/' so the same session is shared by /admin and /api;
 * it is httponly, SameSite=Strict and Secure only when TLS is detected.
 */
function aircoins_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_name(AIRCOINS_SESSION_NAME);
    session_start();
}

/**
 * Best-effort client IP (used for rate limiting and audit).
 *
 * @return string IPv4/IPv6 address or '0.0.0.0' when unknown.
 */
function aircoins_client_ip(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    return is_string($ip) && $ip !== '' ? $ip : '0.0.0.0';
}

/**
 * Is the given IP currently over the failed-login threshold?
 *
 * @param PDO    $pdo Database connection.
 * @param string $ip  Client IP.
 * @return bool True when AIRCOINS_RATE_MAX failures occurred within the window.
 */
function aircoins_rate_limited(PDO $pdo, string $ip): bool
{
    $cutoff = time() - AIRCOINS_RATE_WINDOW;
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE ip = :ip AND attempted_at > :cutoff');
    $stmt->execute([':ip' => $ip, ':cutoff' => $cutoff]);
    return ((int) $stmt->fetchColumn()) >= AIRCOINS_RATE_MAX;
}

/**
 * Record a failed login attempt and prune rows older than one hour.
 *
 * @param PDO    $pdo Database connection.
 * @param string $ip  Client IP.
 */
function aircoins_record_attempt(PDO $pdo, string $ip): void
{
    $now = time();

    $ins = $pdo->prepare('INSERT INTO login_attempts (ip, attempted_at) VALUES (:ip, :ts)');
    $ins->execute([':ip' => $ip, ':ts' => $now]);

    $del = $pdo->prepare('DELETE FROM login_attempts WHERE attempted_at < :old');
    $del->execute([':old' => $now - 3600]);
}

/**
 * Clear recorded failed attempts for an IP (call after a successful login).
 *
 * @param PDO    $pdo Database connection.
 * @param string $ip  Client IP.
 */
function aircoins_clear_attempts(PDO $pdo, string $ip): void
{
    $stmt = $pdo->prepare('DELETE FROM login_attempts WHERE ip = :ip');
    $stmt->execute([':ip' => $ip]);
}

/**
 * Attempt to authenticate a username/password pair.
 *
 * Enforces the rate limit first. On success the session id is regenerated and
 * the login metadata is stored; on failure an attempt is recorded.
 *
 * @param PDO    $pdo      Database connection.
 * @param string $username Submitted username.
 * @param string $password Submitted plaintext password.
 * @return array|null The admin row on success, null on failure or lockout.
 */
function aircoins_login_ok(PDO $pdo, string $username, string $password): ?array
{
    $ip = aircoins_client_ip();

    if (aircoins_rate_limited($pdo, $ip)) {
        return null;
    }

    $stmt = $pdo->prepare('SELECT * FROM admins WHERE username = :u LIMIT 1');
    $stmt->execute([':u' => $username]);
    $admin = $stmt->fetch();

    if (!is_array($admin) || !aircoins_verify($password, (string) ($admin['pass_hash'] ?? ''))) {
        aircoins_record_attempt($pdo, $ip);
        return null;
    }

    // Migrate legacy/weak hashes to the current algorithm on successful login.
    if (aircoins_needs_rehash((string) $admin['pass_hash'])) {
        $newHash = aircoins_hash($password);
        $upd = $pdo->prepare('UPDATE admins SET pass_hash = :h WHERE id = :id');
        $upd->execute([':h' => $newHash, ':id' => (int) $admin['id']]);
        $admin['pass_hash'] = $newHash;
    }

    if (session_status() !== PHP_SESSION_ACTIVE) {
        aircoins_session_start();
    }
    session_regenerate_id(true);

    $now = time();
    $_SESSION['user_id']       = (int) $admin['id'];
    $_SESSION['login_time']    = $now;
    $_SESSION['last_activity'] = $now;

    // The rate-limit counter is only for failures; reset it on success.
    aircoins_clear_attempts($pdo, $ip);

    return $admin;
}

/**
 * Require an authenticated admin for the current request.
 *
 * Starts the session, enforces the idle timeout (destroying the session and
 * redirecting to login.php?timeout=1 when expired) and returns the live admin
 * row. Unauthenticated callers are redirected to login.php. This function
 * always returns or terminates the request.
 *
 * @return array The authenticated admin row.
 */
function aircoins_require_login(): array
{
    aircoins_session_start();

    $now = time();

    if (empty($_SESSION['user_id'])) {
        aircoins_redirect_login();
    }

    $last = $_SESSION['last_activity'] ?? null;
    if ($last !== null && ($now - (int) $last) > AIRCOINS_IDLE_TIMEOUT) {
        aircoins_session_destroy();
        aircoins_redirect_login(true);
    }

    $_SESSION['last_activity'] = $now;

    $pdo  = aircoins_db();
    $stmt = $pdo->prepare('SELECT * FROM admins WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => (int) $_SESSION['user_id']]);
    $admin = $stmt->fetch();

    if (!is_array($admin)) {
        aircoins_session_destroy();
        aircoins_redirect_login();
    }

    return $admin;
}

/**
 * Destroy the current session and its cookie.
 */
function aircoins_session_destroy(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}

/**
 * Redirect to the admin login page and terminate.
 *
 * @param bool $timeout When true, append ?timeout=1 so the UI can explain.
 */
function aircoins_redirect_login(bool $timeout = false): void
{
    if (!headers_sent()) {
        header('Location: login.php' . ($timeout ? '?timeout=1' : ''));
    }
    exit;
}

/**
 * Write an entry to the audit log.
 *
 * @param PDO         $pdo      Database connection.
 * @param int|null    $admin_id Acting admin (null for system/anonymous events).
 * @param string      $action   Short machine-readable action name.
 * @param string|null $detail   Optional human-readable context.
 */
function aircoins_audit(PDO $pdo, ?int $admin_id, string $action, ?string $detail = null): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO audit_log (admin_id, action, detail, ip, ts) VALUES (:admin_id, :action, :detail, :ip, :ts)'
    );
    $stmt->execute([
        ':admin_id' => $admin_id,
        ':action'   => $action,
        ':detail'   => $detail,
        ':ip'       => aircoins_client_ip(),
        ':ts'       => time(),
    ]);
}
