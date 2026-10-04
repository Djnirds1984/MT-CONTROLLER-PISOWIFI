<?php
/**
 * AIRCOINS NETFI — Coin-slot session crediting endpoint (JSON, NO admin auth).
 *
 * Called by the NodeMCU firmware after a coin is inserted. Creates or extends
 * a RADIUS user keyed by the client's MAC address (uppercase, colon-free).
 *
 * Contract:
 *   POST ?mac=AABBCCDDEEFF&coins=N
 *   200 {status:"true", coins:N, time_added:"15m", mac:"AABBCCDDEEFF", new_total:N}
 *   200 {status:"false", error:"no_coin"}
 *   400 {status:"false", error:"invalid_mac"}
 *
 * The firmware sends the client MAC and the number of coins detected.
 * This endpoint calculates the time to add (coins * minutes_per_pulse * 60)
 * and creates/extends the RADIUS user accordingly.
 *
 * CORS is opened for this endpoint (NodeMCU calls it directly).
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/radius_db.php';

// --- response headers (permissive CORS, no caching) -------------------------
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('Access-Control-Allow-Origin: *');
header('X-Content-Type-Options: nosniff');

// --- validate input ---------------------------------------------------------
$mac = isset($_REQUEST['mac']) ? strtoupper(trim((string) $_REQUEST['mac'])) : '';
// Normalize: remove colons, dashes, dots
$mac = str_replace([':', '-', '.'], '', $mac);

if (strlen($mac) !== 12 || !ctype_upper((string) $mac) && !ctype_alnum((string) $mac)) {
    // Re-validate after normalization
    $mac = strtoupper($mac);
    if (strlen($mac) !== 12 || !ctype_alnum($mac)) {
        aircoins_json(['status' => 'false', 'error' => 'invalid_mac',
            'detail' => 'MAC must be 12 hex chars'], 400);
    }
}

$coins = isset($_REQUEST['coins']) ? (int) $_REQUEST['coins'] : 1;
if ($coins <= 0) {
    $coins = 1;
}

try {
    $pdo = aircoins_radius_db();
    aircoins_radius_schema($pdo);

    // Look up the vendo device to get minutes_per_pulse (default 15)
    $minutesPerPulse = 15;
    try {
        $localPdo = aircoins_db();
        aircoins_schema($localPdo);
        $stmt = $localPdo->prepare(
            'SELECT minutes_per_pulse FROM vendo_devices WHERE UPPER(mac_address) = ? AND status = \'accepted\''
        );
        // Normalize MAC for lookup (with colons)
        $macWithColons = implode(':', str_split($mac, 2));
        $stmt->execute([strtoupper($macWithColons)]);
        $row = $stmt->fetch();
        if ($row && (int) $row['minutes_per_pulse'] > 0) {
            $minutesPerPulse = (int) $row['minutes_per_pulse'];
        }
    } catch (Throwable $e) {
        // Fall back to default 15 minutes per pulse
    }

    // Calculate time to add
    $addSeconds = $coins * $minutesPerPulse * 60;

    // Check if user already exists
    $existing = aircoins_radius_find_user($pdo, $mac);

    if ($existing) {
        // Extend existing session
        $newTotal = aircoins_radius_extend_session($pdo, $mac, $addSeconds);
    } else {
        // Create new RADIUS user (MAC as both username and password)
        aircoins_radius_add_user($pdo, $mac, $mac, $addSeconds, 'vendo-coin');
        $newTotal = $addSeconds;
    }

    // Format time for display
    $addMins = intdiv($addSeconds, 60);
    $timeAdded = $addMins . 'm';

    aircoins_json([
        'status'     => 'true',
        'coins'      => $coins,
        'time_added' => $timeAdded,
        'mac'        => $mac,
        'new_total'  => $newTotal,
        'detail'     => $existing ? 'Extended' : 'Created',
    ], 200);

} catch (Throwable $e) {
    aircoins_json([
        'status' => 'false',
        'error'  => 'server_error',
        'detail' => $e->getMessage(),
    ], 500);
}
