<?php
/**
 * AIRCOINS NETFI — CSRF protection.
 *
 * A single random token is stored per session. Every state-changing POST must
 * carry it (csrf_field() renders the hidden input) and be checked with
 * csrf_verify() using a timing-safe comparison.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';

/**
 * Return the per-session CSRF token, generating it on first use.
 *
 * @return string 64-char hex token.
 */
function csrf_token(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        aircoins_session_start();
    }
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Render a hidden input carrying the CSRF token for inclusion in forms.
 *
 * @return string HTML snippet.
 */
function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

/**
 * Verify the CSRF token on POST requests; abort with HTTP 403 on mismatch.
 *
 * Non-POST requests are ignored so this can be called unconditionally at the
 * top of an endpoint.
 */
function csrf_verify(): void
{
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method !== 'POST') {
        return;
    }

    $sent = $_POST['csrf_token'] ?? '';
    if (session_status() !== PHP_SESSION_ACTIVE) {
        aircoins_session_start();
    }
    $expected = $_SESSION['csrf_token'] ?? '';

    if (!is_string($sent) || !is_string($expected) || $expected === '' || !hash_equals($expected, $sent)) {
        if (!headers_sent()) {
            http_response_code(403);
        }
        exit('CSRF validation failed');
    }
}
