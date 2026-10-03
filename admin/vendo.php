<?php
/**
 * AIRCOINS NETFI — Vendo Setup page.
 *
 * Manages NodeMCU ESP8266 coin-slot vending machines in a centralized system.
 * Auto-discovers pending vendo devices from DHCP leases, accepts them (making
 * their IP static, adding bypass/walled-garden rules), and tracks them in the
 * vendo_devices database table.
 *
 * Accepted devices are shown as cards with editable per-device settings:
 *   - Device name (shown in the portal dropdown)
 *   - Coin pin (GPIO where the coin acceptor is connected)
 *   - Debounce (ms) — pulse debounce guard
 *   - Minutes per pulse — time granted per coin pulse (e.g. 15 = 15 min/pulse)
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

    $vendoId  = (int) ($_POST['vendo_id'] ?? 0);
    $routerId = (int) ($_POST['router_id'] ?? 0);

    // ---- save_settings: update card fields --------------------------------
    if ($action === 'save_settings') {
        $deviceName   = trim((string) ($_POST['device_name'] ?? ''));
        $coinPin      = (int) ($_POST['coin_pin'] ?? 4);
        $debounceMs   = (int) ($_POST['debounce_ms'] ?? 150);
        $minutesPerPulse = (int) ($_POST['minutes_per_pulse'] ?? 15);

        // Validate ranges.
        if ($coinPin < 0 || $coinPin > 16) $coinPin = 4;
        if ($debounceMs < 10 || $debounceMs > 5000) $debounceMs = 150;
        if ($minutesPerPulse < 1) $minutesPerPulse = 15;

        try {
            $upd = $pdo->prepare(
                'UPDATE vendo_devices SET device_name = :dn, coin_pin = :cp, '
                . 'debounce_ms = :db, minutes_per_pulse = :mp WHERE id = :id'
            );
            $upd->execute([
                ':dn' => $deviceName,
                ':cp' => $coinPin,
                ':db' => $debounceMs,
                ':mp' => $minutesPerPulse,
                ':id' => $vendoId,
            ]);
            aircoins_flash('success', 'Settings saved for vendo #' . $vendoId . '.');
        } catch (Throwable $e) {
            aircoins_flash('error', 'Save failed: ' . $e->getMessage());
        }
        header('Location: vendo.php');
        exit;
    }

    // ---- accept / remove: require router ----------------------------------
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
            $client->makeDhcpLeaseStatic($mac, 'vendo:' . $mac);
            $client->addIpBinding($ip, 'vendo-bypass:' . $mac);
            $client->addWalledGarden($ip, 'vendo-wg:' . $mac);

            $upd = $pdo->prepare(
                'UPDATE vendo_devices SET status = :status, assigned_ip = :aip, '
                . 'accepted_at = :ts, last_seen = :ts WHERE id = :id'
            );
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
            $wgList = $client->walledGarden();
            foreach ($wgList as $wg) {
                if (strpos((string) ($wg['comment'] ?? ''), 'vendo-wg:' . $mac) !== false) {
                    $client->deleteWalledGarden((string) $wg['.id']);
                }
            }

            $bindList = $client->ipBindings();
            foreach ($bindList as $b) {
                if (strpos((string) ($b['comment'] ?? ''), 'vendo-bypass:' . $mac) !== false) {
                    $client->deleteIpBinding((string) $b['.id']);
                }
            }

            $upd = $pdo->prepare(
                "UPDATE vendo_devices SET status = 'pending', assigned_ip = NULL, "
                . "accepted_at = NULL WHERE id = :id"
            );
            $upd->execute([':id' => $vendoId]);

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

        $leases = $client->dhcpLeases();

        $espOuis = [
            '18:FE:34', '24:0A:C4', '2C:3A:E8', '5C:CF:7F',
            '60:01:94', '68:C6:3A', '84:0D:8E', '84:F3:EB',
            'A0:20:A6', 'B4:E6:2D', 'C4:4F:33', 'CC:50:E3',
            'D8:A0:1D', 'DC:4F:22', 'EC:FA:BC', 'F0:FE:6B',
        ];

        $existingStmt = $pdo->query('SELECT mac_address, status, assigned_ip FROM vendo_devices');
        $existing = [];
        while ($row = $existingStmt->fetch()) {
            $existing[strtoupper((string) $row['mac_address'])] = $row;
        }

        foreach ($leases as $lease) {
            $macRaw   = strtoupper((string) ($lease['mac-address'] ?? ''));
            $ip       = (string) ($lease['address'] ?? '');
            $hostname = (string) ($lease['host-name'] ?? '');

            $isEsp = false;
            foreach ($espOuis as $oui) {
                if (strpos($macRaw, $oui) === 0) { $isEsp = true; break; }
            }
            if (!$isEsp && stripos($hostname, 'vendo') === 0) {
                $isEsp = true;
            }
            if (!$isEsp) continue;

            $ex = $existing[$macRaw] ?? null;
            if ($ex && (string) ($ex['status'] ?? '') === 'accepted') {
                continue; // loaded separately below with full DB row
            }

            // Upsert as pending.
            try {
                $ins = $pdo->prepare(
                    'INSERT INTO vendo_devices (mac_address, ip_address, hostname, router_id, status, created_at, last_seen)
                     VALUES (:mac, :ip, :hn, :rid, \'pending\', :now, :now)
                     ON CONFLICT(mac_address) DO UPDATE SET ip_address = :ip2, last_seen = :now2'
                );
                $ins->execute([
                    ':mac' => $macRaw, ':ip' => $ip, ':hn' => $hostname,
                    ':rid' => $routerId, ':now' => time(), ':ip2' => $ip, ':now2' => time(),
                ]);
            } catch (Throwable $e) { /* non-fatal */ }

            $pending[] = [
                'mac_address' => $macRaw,
                'ip_address'  => $ip,
                'hostname'    => $hostname,
            ];
        }
    } catch (Throwable $e) {
        aircoins_flash('error', 'Router query failed: ' . $e->getMessage());
    }
}

// Load ALL accepted vendos from DB (with full settings).
try {
    $accStmt = $pdo->query(
        "SELECT * FROM vendo_devices WHERE status = 'accepted' ORDER BY device_name ASC, id ASC"
    );
    while ($row = $accStmt->fetch()) {
        $accepted[] = $row;
    }
} catch (Throwable $e) { /* empty */ }

// ---- Render ----------------------------------------------------------------

aircoins_header('Vendo Setup', 'vendo');
?>

<!-- Pending Devices -->
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
                <form method="post" action="vendo.php" style="display:inline" data-confirm="Accept this vendo device?">
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

<!-- Accepted Devices — Card Layout -->
<div class="card">
  <div class="card__head">
    <h2 class="card__title">Accepted Vendo Devices</h2>
    <span class="card__sub">Each card represents a coin-slot node — configure pin, debounce, and minutes per pulse</span>
  </div>
  <div class="card__body">
    <?php if ($accepted === []): ?>
      <p class="muted">No accepted vendo devices yet. Accept a pending device above to get started.</p>
    <?php else: ?>
      <div style="display:grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap:16px;">
        <?php foreach ($accepted as $a): ?>
          <?php
            $aId   = (int) $a['id'];
            $aName = (string) ($a['device_name'] ?? '');
            if ($aName === '') {
                $aName = 'Vendo ' . strtoupper(substr((string) $a['mac_address'], -5));
            }
            $aIp   = (string) ($a['assigned_ip'] ?: $a['ip_address']);
            $aMac  = (string) $a['mac_address'];
            $aPin  = (int) ($a['coin_pin'] ?? 4);
            $aDb   = (int) ($a['debounce_ms'] ?? 150);
            $aMin = (int) ($a['minutes_per_pulse'] ?? 15);
          ?>
          <div style="border:2px solid #e0e0e0; border-radius:12px; padding:16px; background:#fafafa;">
            <!-- Header: name + IP -->
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
              <div>
                <strong style="font-size:16px; color:#00BCD4;"><?php echo e($aName); ?></strong><br>
                <code style="font-size:11px; color:#888;"><?php echo e($aMac); ?></code>
              </div>
              <div style="text-align:right;">
                <span style="display:inline-block; padding:3px 8px; background:#4caf50; color:#fff; border-radius:12px; font-size:11px; font-weight:700;">ONLINE</span><br>
                <span style="font-size:12px; color:#555;"><?php echo e($aIp); ?></span>
              </div>
            </div>

            <!-- Settings form -->
            <form method="post" action="vendo.php">
              <?php echo csrf_field(); ?>
              <input type="hidden" name="action" value="save_settings">
              <input type="hidden" name="vendo_id" value="<?php echo $aId; ?>">

              <label style="display:block; font-size:11px; font-weight:700; color:#666; text-transform:uppercase; margin-bottom:3px;">Device Name</label>
              <input type="text" name="device_name" value="<?php echo e($aName); ?>"
                     style="width:100%; padding:7px; border:1px solid #ddd; border-radius:6px; font-size:13px; margin-bottom:8px; box-sizing:border-box;"
                     placeholder="e.g. Vendo 1 - Lobby">

              <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px;">
                <div>
                  <label style="display:block; font-size:11px; font-weight:700; color:#666; text-transform:uppercase; margin-bottom:3px;">Coin Pin (GPIO)</label>
                  <select name="coin_pin" style="width:100%; padding:7px; border:1px solid #ddd; border-radius:6px; font-size:13px; box-sizing:border-box;">
                    <?php foreach ([0=>'D0 (Flash)', 2=>'D2 (Coin)', 4=>'D4 (LED)', 5=>'D1', 12=>'D6', 13=>'D7', 14=>'D5', 15=>'D8', 16=>'D3 (Setup)'] as $gpio => $lbl): ?>
                      <option value="<?php echo $gpio; ?>" <?php echo $aPin === $gpio ? 'selected' : ''; ?>>
                        <?php echo e($lbl); ?> — GPIO<?php echo $gpio; ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div>
                  <label style="display:block; font-size:11px; font-weight:700; color:#666; text-transform:uppercase; margin-bottom:3px;">Debounce (ms)</label>
                  <input type="number" name="debounce_ms" value="<?php echo $aDb; ?>" min="10" max="5000" step="10"
                         style="width:100%; padding:7px; border:1px solid #ddd; border-radius:6px; font-size:13px; box-sizing:border-box;">
                </div>
              </div>

              <label style="display:block; font-size:11px; font-weight:700; color:#666; text-transform:uppercase; margin:8px 0 3px;">Minutes per Pulse</label>
              <input type="number" name="minutes_per_pulse" value="<?php echo $aMin; ?>" min="1" max="1440" step="1"
                     style="width:100%; padding:7px; border:1px solid #ddd; border-radius:6px; font-size:13px; margin-bottom:4px; box-sizing:border-box;">
              <small style="color:#888; font-size:11px;">Each coin = this many minutes of internet time</small>

              <div style="display:flex; gap:8px;">
                <button type="submit" class="btn btn--primary btn--sm" style="flex:1;">Save Settings</button>
              </div>
            </form>

            <!-- Remove button -->
            <form method="post" action="vendo.php" style="margin-top:10px;" data-confirm="Remove this vendo? This will delete the static lease, binding, and walled-garden rules.">
              <?php echo csrf_field(); ?>
              <input type="hidden" name="action" value="remove">
              <input type="hidden" name="vendo_id" value="<?php echo $aId; ?>">
              <input type="hidden" name="router_id" value="<?php echo $routerId; ?>">
              <button type="submit" class="btn btn--danger btn--sm" style="width:100%;">Unassign / Remove</button>
            </form>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php aircoins_footer();
