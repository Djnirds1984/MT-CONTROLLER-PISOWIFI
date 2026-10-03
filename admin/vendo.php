<?php
/**
 * AIRCOINS NETFI — Vendo Setup page.
 *
 * Manages NodeMCU ESP8266 coin-slot vending machines in a centralized system.
 * Auto-discovers pending vendo devices from DHCP leases, accepts them (making
 * their IP static, adding bypass/walled-garden rules), and tracks them in the
 * vendo_devices database table.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/RouterOS/RouterFactory.php';

aircoins_require_login();

$pdo     = aircoins_db();
aircoins_schema($pdo);

// ---- POST handlers --------------------------------------------------------

$action = (string) ($_POST['action'] ?? '');

if ($action !== '' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $vendoId = (int) ($_POST['vendo_id'] ?? 0);
    $routerId = (int) ($_POST['router_id'] ?? 0);

    if ($routerId <= 0) {
        aircoins_flash('error', 'No router selected. Select a router from the top bar first.');
        header('Location: vendo.php');
        exit;
    }

    try {
        $router = aircoins_get_router($pdo, $routerId);
        if (!$router) {
            throw new RuntimeException('Router not found.');
        }
        $client = aircoins_router_client($router);
    } catch (Throwable $e) {
        aircoins_flash('error', 'Router connection failed: ' . $e->getMessage());
        header('Location: vendo.php');
        exit;
    }

    if ($action === 'accept') {
        // Fetch vendo record.
        $stmt = $pdo->prepare('SELECT * FROM vendo_devices WHERE id = :id');
        $stmt->execute([':id' => $vendoId]);
        $vendo = $stmt->fetch();

        if (!$vendo) {
            aircoins_flash('error', 'Vendo device not found.');
            header('Location: vendo.php');
            exit;
        }

        $mac = (string) $vendo['mac_address'];
        $ip  = (string) $vendo['ip_address'];

        try {
            // 1. Make DHCP lease static.
            $client->makeDhcpLeaseStatic($mac, 'vendo:' . $mac);

            // 2. Add IP binding bypass.
            $binding = $client->addIpBinding($ip, 'vendo-bypass:' . $mac);

            // 3. Add walled garden entry.
            $wg = $client->addWalledGarden($ip, 'vendo-wg:' . $mac);

            // 4. Update DB record.
            $upd = $pdo->prepare('UPDATE vendo_devices SET status = :status, assigned_ip = :aip, accepted_at = :ts, last_seen = :ts WHERE id = :id');
            $upd->execute([
                ':status' => 'accepted',
                ':aip'    => $ip,
                ':ts'     => time(),
                ':id'     => $vendoId,
            ]);

            aircoins_audit($pdo, $_SESSION['admin_id'] ?? null, 'vendo_accept',
                'router #' . $routerId . ' vendo ' . $mac . ' ip ' . $ip);
            aircoins_flash('success', 'Vendo ' . $mac . ' accepted — static IP, bypass, and walled garden configured.');
        } catch (Throwable $e) {
            aircoins_flash('error', 'Accept failed: ' . $e->getMessage());
        }

        header('Location: vendo.php');
        exit;
    }

    if ($action === 'remove') {
        // Fetch vendo record.
        $stmt = $pdo->prepare('SELECT * FROM vendo_devices WHERE id = :id');
        $stmt->execute([':id' => $vendoId]);
        $vendo = $stmt->fetch();

        if (!$vendo) {
            aircoins_flash('error', 'Vendo device not found.');
            header('Location: vendo.php');
            exit;
        }

        $mac = (string) $vendo['mac_address'];
        $ip  = (string) $vendo['assigned_ip'];

        try {
            // Remove walled garden entries matching this vendo.
            $wgList = $client->walledGarden();
            foreach ($wgList as $wg) {
                if (strpos((string) ($wg['comment'] ?? ''), 'vendo-wg:' . $mac) !== false) {
                    $client->deleteWalledGarden((string) $wg['.id']);
                }
            }

            // Remove IP binding entries matching this vendo.
            $bindList = $client->ipBindings();
            foreach ($bindList as $b) {
                if (strpos((string) ($b['comment'] ?? ''), 'vendo-bypass:' . $mac) !== false) {
                    $client->deleteIpBinding((string) $b['.id']);
                }
            }

            // Update DB record.
            $upd = $pdo->prepare('UPDATE vendo_devices SET status = :status, assigned_ip = NULL, accepted_at = NULL WHERE id = :id');
            $upd->execute([':status' => 'pending', ':id' => $vendoId]);

            aircoins_audit($pdo, $_SESSION['admin_id'] ?? null, 'vendo_remove',
                'router #' . $routerId . ' vendo ' . $mac);
            aircoins_flash('success', 'Vendo ' . $mac . ' unassigned — router rules removed.');
        } catch (Throwable $e) {
            aircoins_flash('error', 'Remove failed: ' . $e->getMessage());
        }

        header('Location: vendo.php');
        exit;
    }
}

// ---- GET: discover pending vendos + load accepted --------------------------

$routerId   = aircoins_selected_router_id();
$pending    = [];
$accepted   = [];

if ($routerId > 0) {
    try {
        $router = aircoins_get_router($pdo, $routerId);
        if (!$router) {
            throw new RuntimeException('Router not found.');
        }
        $client = aircoins_router_client($router);

        // Fetch DHCP leases to discover ESP8266 NodeMCU devices.
        $leases = $client->dhcpLeases();

        // ESP8266 MAC prefixes (OUI): 18:FE:34, 24:0A:C4, 2C:3A:E8,
        // 5C:CF:7F, 60:01:94, 68:C6:3A, 84:0D:8E, 84:F3:EB,
        // A0:20:A6, B4:E6:2D, C4:4F:33, CC:50:E3, D8:A0:1D,
        // DC:4F:22, EC:FA:BC, F0:FE:6B
        $espOuis = [
            '18:FE:34', '24:0A:C4', '2C:3A:E8', '5C:CF:7F',
            '60:01:94', '68:C6:3A', '84:0D:8E', '84:F3:EB',
            'A0:20:A6', 'B4:E6:2D', 'C4:4F:33', 'CC:50:E3',
            'D8:A0:1D', 'DC:4F:22', 'EC:FA:BC', 'F0:FE:6B',
        ];

        // Load existing vendo devices from DB.
        $existingStmt = $pdo->query('SELECT mac_address, status, assigned_ip FROM vendo_devices');
        $existing = [];
        while ($row = $existingStmt->fetch()) {
            $existing[strtoupper((string) $row['mac_address'])] = $row;
        }

        foreach ($leases as $lease) {
            $macRaw   = strtoupper((string) ($lease['mac-address'] ?? ''));
            $ip       = (string) ($lease['address'] ?? '');
            $hostname = (string) ($lease['host-name'] ?? '');

            // Match: ESP8266 OUI prefix OR hostname starting with "vendo"
            $isEsp = false;
            foreach ($espOuis as $oui) {
                if (strpos($macRaw, $oui) === 0) {
                    $isEsp = true;
                    break;
                }
            }
            if (!$isEsp && stripos($hostname, 'vendo') === 0) {
                $isEsp = true;
            }

            if (!$isEsp) {
                continue;
            }

            // Skip if already in DB as accepted.
            $ex = $existing[$macRaw] ?? null;
            if ($ex && (string) ($ex['status'] ?? '') === 'accepted') {
                $accepted[] = [
                    'mac_address' => $macRaw,
                    'ip_address'  => $ip,
                    'hostname'    => $hostname,
                    'status'      => 'accepted',
                    'assigned_ip' => (string) ($ex['assigned_ip'] ?? $ip),
                ];
                continue;
            }

            // Upsert into vendo_devices as pending.
            try {
                $ins = $pdo->prepare('INSERT INTO vendo_devices (mac_address, ip_address, hostname, router_id, status, created_at, last_seen)
                    VALUES (:mac, :ip, :hn, :rid, \'pending\', :now, :now)
                    ON CONFLICT(mac_address) DO UPDATE SET ip_address = :ip2, last_seen = :now2');
                $ins->execute([
                    ':mac'  => $macRaw,
                    ':ip'   => $ip,
                    ':hn'   => $hostname,
                    ':rid'  => $routerId,
                    ':now'  => time(),
                    ':ip2'  => $ip,
                    ':now2' => time(),
                ]);
            } catch (Throwable $e) {
                // Non-fatal: device still shows in discovery.
            }

            $pending[] = [
                'mac_address' => $macRaw,
                'ip_address'  => $ip,
                'hostname'    => $hostname,
                'status'      => 'pending',
            ];
        }

        // Also load accepted vendos from DB that may not be in current leases.
        $accStmt = $pdo->prepare('SELECT * FROM vendo_devices WHERE router_id = :rid AND status = \'accepted\' ORDER BY accepted_at DESC');
        $accStmt->execute([':rid' => $routerId]);
        while ($row = $accStmt->fetch()) {
            $macRaw = strtoupper((string) $row['mac_address']);
            // Skip if already added from leases.
            $found = false;
            foreach ($accepted as $a) {
                if ($a['mac_address'] === $macRaw) { $found = true; break; }
            }
            if (!$found) {
                $accepted[] = [
                    'mac_address' => $macRaw,
                    'ip_address'  => (string) ($row['ip_address'] ?? ''),
                    'hostname'    => (string) ($row['hostname'] ?? ''),
                    'status'      => 'accepted',
                    'assigned_ip' => (string) ($row['assigned_ip'] ?? ''),
                    'db_id'       => (int) $row['id'],
                ];
            }
        }
    } catch (Throwable $e) {
        aircoins_flash('error', 'Router query failed: ' . $e->getMessage());
    }
}

// ---- Render ----------------------------------------------------------------

aircoins_header('Vendo Setup', 'vendo');
?>

<div class="card">
  <div class="card__head">
    <h2 class="card__title">Pending Vendo Devices</h2>
    <span class="card__sub">Discovered from DHCP leases — ESP8266 MAC prefixes or "vendo-*" hostnames</span>
  </div>
  <div class="card__body">
    <?php if ($routerId <= 0): ?>
      <p class="muted">Select a router from the top bar to discover vendo devices.</p>
    <?php elseif ($pending === []): ?>
      <p class="muted">No pending vendo devices found. Make sure your NodeMCU is connected and has obtained a DHCP lease.</p>
    <?php else: ?>
      <table class="table">
        <thead>
          <tr>
            <th>MAC Address</th>
            <th>IP Address</th>
            <th>Hostname</th>
            <th style="width:120px">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($pending as $p): ?>
            <?php
            // Find DB id for this pending device.
            $pStmt = $pdo->prepare('SELECT id FROM vendo_devices WHERE mac_address = :mac');
            $pStmt->execute([':mac' => $p['mac_address']]);
            $pRow = $pStmt->fetch();
            $pId = $pRow ? (int) $pRow['id'] : 0;
            ?>
            <tr>
              <td><code><?php echo e($p['mac_address']); ?></code></td>
              <td><?php echo e($p['ip_address']); ?></td>
              <td><?php echo e($p['hostname'] ?: '—'); ?></td>
              <td>
                <form method="post" action="vendo.php" style="display:inline" data-confirm="Accept this vendo device? This will assign a static IP, add a bypass binding, and create a walled-garden rule.">
                  <?php echo csrf_field(); ?>
                  <input type="hidden" name="action" value="accept">
                  <input type="hidden" name="vendo_id" value="<?php echo $pId; ?>">
                  <input type="hidden" name="router_id" value="<?php echo $routerId; ?>">
                  <button class="btn btn--primary btn--sm" type="submit">Accept</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <div class="card__head">
    <h2 class="card__title">Accepted Vendo Devices</h2>
    <span class="card__sub">Static IP assigned, bypassed from hotspot, walled-garden configured</span>
  </div>
  <div class="card__body">
    <?php if ($accepted === []): ?>
      <p class="muted">No accepted vendo devices yet.</p>
    <?php else: ?>
      <table class="table">
        <thead>
          <tr>
            <th>MAC Address</th>
            <th>Assigned IP</th>
            <th>Hostname</th>
            <th style="width:120px">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($accepted as $a): ?>
            <?php
            // Find DB id.
            $aStmt = $pdo->prepare('SELECT id FROM vendo_devices WHERE mac_address = :mac');
            $aStmt->execute([':mac' => $a['mac_address']]);
            $aRow = $aStmt->fetch();
            $aId = $aRow ? (int) $aRow['id'] : ($a['db_id'] ?? 0);
            ?>
            <tr>
              <td><code><?php echo e($a['mac_address']); ?></code></td>
              <td><?php echo e($a['assigned_ip'] ?: $a['ip_address']); ?></td>
              <td><?php echo e($a['hostname'] ?: '—'); ?></td>
              <td>
                <form method="post" action="vendo.php" style="display:inline" data-confirm="Remove this vendo? This will delete the static lease, binding, and walled-garden rules.">
                  <?php echo csrf_field(); ?>
                  <input type="hidden" name="action" value="remove">
                  <input type="hidden" name="vendo_id" value="<?php echo $aId; ?>">
                  <input type="hidden" name="router_id" value="<?php echo $routerId; ?>">
                  <button class="btn btn--danger btn--sm" type="submit">Remove</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</div>

<?php aircoins_footer();
