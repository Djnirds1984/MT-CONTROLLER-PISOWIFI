<?php
/**
 * AIRCOINS NETFI — portal-facing session lookup (JSON, NO admin auth).
 *
 * The SBC-served status page (hotspot/status.html) polls this endpoint with the
 * client's own MAC address to render live session data. It is intentionally
 * unauthenticated but strictly read-only: it only reports whether the given MAC
 * currently has an active hotspot session on any enabled router.
 *
 * Contract:
 *   GET ?mac=AA:BB:CC:DD:EE:FF   (separators optional: ':' '-' '.' or none)
 *   200 {connected:true, user, uptime, bytes_in, bytes_out, time_left}
 *   200 {connected:false}        when no router has the MAC active
 *   400 {connected:false,error}  when the mac parameter is missing/malformed
 *
 * CORS is opened (Access-Control-Allow-Origin: *) for THIS endpoint only, since
 * the payload is non-sensitive live status for the caller's own MAC. Stack
 * traces are never leaked: all client calls run inside try/catch.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/RouterOS/RouterFactory.php';

// --- response headers (permissive CORS, no caching) -------------------------
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('Access-Control-Allow-Origin: *');
header('X-Content-Type-Options: nosniff');

/**
 * Normalise a MAC address to canonical upper-case colon form.
 *
 * Accepts 12 hex digits with optional ':' / '-' / '.' separators.
 *
 * @return string|null "AA:BB:CC:DD:EE:FF" or null when invalid.
 */
function aircoins_norm_mac(string $raw): ?string
{
    $hex = preg_replace('/[^0-9A-Fa-f]/', '', $raw);
    if ($hex === null || strlen($hex) !== 12) {
        return null;
    }
    $pairs = str_split(strtolower($hex), 2);
    return strtoupper(implode(':', $pairs));
}

// --- validate input ---------------------------------------------------------
$rawMac = isset($_GET['mac']) ? (string) $_GET['mac'] : '';
$mac = aircoins_norm_mac($rawMac);

if ($rawMac === '' || $mac === null) {
    aircoins_json(['connected' => false, 'error' => 'invalid_mac'], 400);
}

// --- iterate enabled routers, first active hit wins -------------------------
$found = null;
try {
    $pdo = aircoins_db();
    aircoins_schema($pdo);

    $stmt = $pdo->query('SELECT * FROM routers WHERE disabled = 0 ORDER BY id ASC');
    $routers = $stmt ? $stmt->fetchAll() : [];

    foreach ($routers as $row) {
        try {
            $client  = aircoins_router_client($row);
            $session = $client->findActiveByMac($mac);
            if (is_array($session) && ((($session['user'] ?? '') !== '') || (($session['.id'] ?? '') !== ''))) {
                $found = $session;
                break;
            }
        } catch (Throwable $e) {
            // Skip unreachable routers silently; keep scanning the rest.
            continue;
        }
    }
} catch (Throwable $e) {
    // Database or setup failure: report "not connected" without leaking detail.
    aircoins_json(['connected' => false], 200);
}

if ($found === null) {
    aircoins_json(['connected' => false], 200);
}

// time_left is not exposed by the unified session shape; the portal falls back
// to its own snapshot (the `left` query param) when this is null.
$timeLeft = null;
foreach (['time-left', 'session-time-left', 'left'] as $k) {
    if (isset($found[$k]) && $found[$k] !== '') {
        $timeLeft = (string) $found[$k];
        break;
    }
}

aircoins_json([
    'connected' => true,
    'user'      => (string) ($found['user'] ?? ''),
    'uptime'    => (string) ($found['uptime'] ?? ''),
    'bytes_in'  => (int) ($found['bytes-in'] ?? 0),
    'bytes_out' => (int) ($found['bytes-out'] ?? 0),
    'time_left' => $timeLeft,
], 200);
