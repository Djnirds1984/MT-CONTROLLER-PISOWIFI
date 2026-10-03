<?php
/**
 * AIRCOINS NETFI — live monitor feed (JSON).
 *
 * Polled by admin/assets/admin.js every 10s. Requires an authenticated admin
 * session; unauthenticated callers get a 401 JSON body (NOT a redirect, since
 * this is consumed by fetch()).
 *
 * For each enabled router this endpoint reads resource()/identity()/
 * activeSessions()/interfaces() and computes a per-interface traffic RATE by
 * diffing rx-byte/tx-byte against the most recent monitor_samples row, then
 * stores the new sample. Every router is handled inside its own try/catch so a
 * single unreachable device yields an "error" field instead of failing the
 * whole response.
 *
 * Read-only against the routers themselves; the only writes are monitor_samples.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/RouterOS/RouterFactory.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// --- auth gate (JSON, no redirect) ------------------------------------------
aircoins_session_start();

if (empty($_SESSION['user_id'])) {
    aircoins_json(['error' => 'unauthorized'], 401);
}

$now = time();
$last = $_SESSION['last_activity'] ?? null;
if ($last !== null && ($now - (int) $last) > AIRCOINS_IDLE_TIMEOUT) {
    aircoins_session_destroy();
    aircoins_json(['error' => 'session_expired'], 401);
}
$_SESSION['last_activity'] = $now;

$pdo = aircoins_db();
$stmt = $pdo->prepare('SELECT id FROM admins WHERE id = :id LIMIT 1');
$stmt->execute([':id' => (int) $_SESSION['user_id']]);
if (!$stmt->fetch()) {
    aircoins_session_destroy();
    aircoins_json(['error' => 'unauthorized'], 401);
}

// --- helpers ----------------------------------------------------------------

/**
 * Latest stored sample for a (router, interface) pair.
 *
 * @return array{rx_byte:int,tx_byte:int,ts:int}|null
 */
function aircoins_last_sample(PDO $pdo, int $routerId, string $iface): ?array
{
    $stmt = $pdo->prepare(
        'SELECT rx_byte, tx_byte, ts FROM monitor_samples
         WHERE router_id = :rid AND iface = :iface
         ORDER BY ts DESC, id DESC LIMIT 1'
    );
    $stmt->execute([':rid' => $routerId, ':iface' => $iface]);
    $row = $stmt->fetch();
    if (!is_array($row)) {
        return null;
    }
    return [
        'rx_byte' => (int) $row['rx_byte'],
        'tx_byte' => (int) $row['tx_byte'],
        'ts'      => (int) $row['ts'],
    ];
}

/**
 * Persist a fresh traffic sample.
 */
function aircoins_store_sample(PDO $pdo, int $routerId, string $iface, int $rx, int $tx, int $ts): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO monitor_samples (router_id, iface, rx_byte, tx_byte, ts)
         VALUES (:rid, :iface, :rx, :tx, :ts)'
    );
    $stmt->execute([':rid' => $routerId, ':iface' => $iface, ':rx' => $rx, ':tx' => $tx, ':ts' => $ts]);
}

/**
 * Non-negative bytes-per-second between two counter readings.
 */
function aircoins_rate(int $prev, int $curr, int $dt): int
{
    if ($dt <= 0 || $curr < $prev) {
        return 0; // counter reset or no elapsed time
    }
    return (int) round(($curr - $prev) / $dt);
}

// --- main -------------------------------------------------------------------

$pdo = aircoins_db();
aircoins_schema($pdo);

$routers = [];
try {
    $stmt = $pdo->query('SELECT * FROM routers WHERE disabled = 0 ORDER BY name COLLATE NOCASE ASC');
    $routers = $stmt ? $stmt->fetchAll() : [];
} catch (Throwable $e) {
    aircoins_json(['routers' => [], 'error' => 'db_unavailable'], 200);
}

$out = [];

foreach ($routers as $row) {
    $rid  = (int) $row['id'];
    $item = [
        'id'           => $rid,
        'name'         => (string) $row['name'],
        'api_type'     => (string) $row['api_type'],
        'online'       => false,
        'identity'     => null,
        'resource'     => null,
        'active_count' => 0,
        'interfaces'   => [],
        'error'        => null,
    ];

    try {
        $client = aircoins_router_client($row);

        $res = $client->resource();
        $idn = $client->identity();

        $sessions = $client->activeSessions();
        $item['active_count'] = is_array($sessions) ? count($sessions) : 0;

        $ifaces = $client->interfaces();
        $ifaceOut = [];
        foreach ((is_array($ifaces) ? $ifaces : []) as $if) {
            $name = (string) ($if['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $rx = (int) ($if['rx-byte'] ?? 0);
            $tx = (int) ($if['tx-byte'] ?? 0);

            $prev = aircoins_last_sample($pdo, $rid, $name);
            $dt   = $prev ? ($now - $prev['ts']) : 0;
            $rxr  = $prev ? aircoins_rate($prev['rx_byte'], $rx, $dt) : 0;
            $txr  = $prev ? aircoins_rate($prev['tx_byte'], $tx, $dt) : 0;

            aircoins_store_sample($pdo, $rid, $name, $rx, $tx, $now);

            $ifaceOut[] = [
                'name'    => $name,
                'running' => !empty($if['running']),
                'rx_rate' => $rxr,
                'tx_rate' => $txr,
            ];
        }

        // Bound table growth: drop samples older than 24h for this router.
        $prune = $pdo->prepare('DELETE FROM monitor_samples WHERE router_id = :rid AND ts < :cutoff');
        $prune->execute([':rid' => $rid, ':cutoff' => $now - 86400]);

        $item['online']   = true;
        $item['identity'] = (string) ($idn['name'] ?? '');
        $item['resource'] = [
            'cpu-load'     => (float) ($res['cpu-load'] ?? 0),
            'free-memory'  => (int) ($res['free-memory'] ?? 0),
            'total-memory' => (int) ($res['total-memory'] ?? 0),
            'uptime'       => (int) ($res['uptime'] ?? 0),
            'version'      => (string) ($res['version'] ?? ''),
            'board-name'   => (string) ($res['board-name'] ?? ''),
        ];
        $item['interfaces'] = $ifaceOut;
    } catch (Throwable $e) {
        $item['online'] = false;
        $item['error']  = $e->getMessage();
    }

    $out[] = $item;
}

aircoins_json(['routers' => $out, 'ts' => $now], 200);
