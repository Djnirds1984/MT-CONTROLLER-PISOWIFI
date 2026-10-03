<?php
/**
 * AIRCOINS NETFI — portal-facing vendo device list (JSON, NO admin auth).
 *
 * The SBC-served login page (hotspot/login.html) calls this endpoint to
 * dynamically populate the vendo device dropdown so clients can select which
 * coin-slot to use.  Returns all accepted vendo devices with their IP, name,
 * and coin settings.
 *
 * Contract:
 *   GET /api/vendo.php
 *   200 {devices:[{id, name, ip, mac, coin_pin, debounce_ms, rate_per_pulse}, ...]}
 *   200 {devices:[]}   when no accepted vendos exist
 *
 * CORS is opened for THIS endpoint only (same rationale as session.php).
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';

// --- response headers (permissive CORS, no caching) -------------------------
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('Access-Control-Allow-Origin: *');
header('X-Content-Type-Options: nosniff');

try {
    $pdo = aircoins_db();
    aircoins_schema($pdo);

    $stmt = $pdo->query(
        "SELECT id, mac_address, assigned_ip, ip_address, hostname, device_name, "
        . "coin_pin, debounce_ms, rate_per_pulse "
        . "FROM vendo_devices WHERE status = 'accepted' ORDER BY device_name ASC, id ASC"
    );
    $rows = $stmt ? $stmt->fetchAll() : [];

    $devices = [];
    foreach ($rows as $r) {
        $ip = (string) ($r['assigned_ip'] ?: $r['ip_address'] ?: '');
        if ($ip === '') continue; // skip devices without a reachable IP

        $name = (string) ($r['device_name'] ?? '');
        if ($name === '') {
            $name = 'Vendo ' . strtoupper(substr((string) $r['mac_address'], -5));
        }

        $devices[] = [
            'id'              => (int) $r['id'],
            'name'            => $name,
            'ip'              => $ip,
            'mac'             => (string) $r['mac_address'],
            'coin_pin'        => (int) ($r['coin_pin'] ?? 4),
            'debounce_ms'     => (int) ($r['debounce_ms'] ?? 150),
            'rate_per_pulse'  => (float) ($r['rate_per_pulse'] ?? 1.00),
        ];
    }

    aircoins_json(['devices' => $devices], 200);

} catch (Throwable $e) {
    aircoins_json(['devices' => []], 200);
}
