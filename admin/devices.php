<?php
/**
 * AIRCOINS NETFI — Devices management.
 *
 * Lists devices connected to the MikroTik hotspot, auto-syncs from active
 * sessions, and provides full CRUD with manual hostname/session-time editing.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/crypto.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/RouterOS/RouterFactory.php';
require_once __DIR__ . '/../includes/radius_db.php';

$admin   = aircoins_require_login();
$adminId = (int) $admin['id'];

$pdo = aircoins_db();
aircoins_schema($pdo);

$action = (string) ($_REQUEST['action'] ?? '');

// ---- Session-selected router -----------------------------------------------
$routerId = aircoins_selected_router_id();
$router   = aircoins_selected_router($pdo);

// ---- POST handlers ---------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_verify();

    $back = 'Location: devices.php';

    // ---- Sync devices from MikroTik active sessions ------------------------
    if ($action === 'sync') {
        if (!$router) {
            aircoins_flash('error', 'Select a router first.');
            header($back);
            exit;
        }
        try {
            $client     = aircoins_router_client($router);
            $sessions   = $client->activeSessions();
            $now        = time();
            $upserted   = 0;
            $seenMacs   = [];

            // Step 1: Sync from active hotspot sessions (online devices).
            foreach ($sessions as $s) {
                $mac = strtoupper(trim((string) ($s['mac'] ?? '')));
                if ($mac === '') {
                    continue;
                }
                $seenMacs[$mac] = true;
                $ip    = (string) ($s['address'] ?? '');
                $user  = (string) ($s['user'] ?? '');
                $upt   = (string) ($s['uptime'] ?? '');

                $stmt = $pdo->prepare('SELECT id FROM devices WHERE mac_address = :mac LIMIT 1');
                $stmt->execute([':mac' => $mac]);
                $existing = $stmt->fetch();

                if ($existing) {
                    $upd = $pdo->prepare('UPDATE devices SET ip_address = :ip, user = :user, router_id = :rid, session_time = :st, last_seen = :now, updated_at = :now2 WHERE id = :id');
                    $upd->execute([':ip' => $ip, ':user' => $user, ':rid' => $routerId, ':st' => $upt, ':now' => $now, ':now2' => $now, ':id' => (int) $existing['id']]);
                } else {
                    $ins = $pdo->prepare('INSERT INTO devices (mac_address, ip_address, user, router_id, session_time, status, first_seen, last_seen, created_at, updated_at) VALUES (:mac, :ip, :user, :rid, :st, \'active\', :now, :now2, :now3, :now4)');
                    $ins->execute([':mac' => $mac, ':ip' => $ip, ':user' => $user, ':rid' => $routerId, ':st' => $upt, ':now' => $now, ':now2' => $now, ':now3' => $now, ':now4' => $now]);
                }
                $upserted++;
            }

            // Step 2: Sync from DHCP leases (includes devices without active sessions).
            try {
                $leases = $client->dhcpLeases();
                foreach ($leases as $l) {
                    $mac = strtoupper(trim((string) ($l['mac-address'] ?? '')));
                    if ($mac === '') {
                        continue;
                    }
                    $ip       = (string) ($l['address'] ?? '');
                    $hostname = (string) ($l['host-name'] ?? '');

                    $stmt = $pdo->prepare('SELECT id, hostname FROM devices WHERE mac_address = :mac LIMIT 1');
                    $stmt->execute([':mac' => $mac]);
                    $existing = $stmt->fetch();

                    if ($existing) {
                        // Update IP always; hostname only if currently empty.
                        $sql = 'UPDATE devices SET ip_address = :ip, router_id = :rid, last_seen = :now, updated_at = :now2';
                        $params = [':ip' => $ip, ':rid' => $routerId, ':now' => $now, ':now2' => $now, ':id' => (int) $existing['id']];
                        if ((string) ($existing['hostname'] ?? '') === '' && $hostname !== '') {
                            $sql .= ', hostname = :host';
                            $params[':host'] = $hostname;
                        }
                        $sql .= ' WHERE id = :id';
                        $upd = $pdo->prepare($sql);
                        $upd->execute($params);
                    } else {
                        $ins = $pdo->prepare('INSERT INTO devices (mac_address, ip_address, hostname, router_id, status, first_seen, last_seen, created_at, updated_at) VALUES (:mac, :ip, :host, :rid, \'active\', :now, :now2, :now3, :now4)');
                        $ins->execute([':mac' => $mac, ':ip' => $ip, ':host' => $hostname, ':rid' => $routerId, ':now' => $now, ':now2' => $now, ':now3' => $now, ':now4' => $now]);
                    }
                    if (!isset($seenMacs[$mac])) {
                        $upserted++;
                    }
                }
            } catch (Throwable $le) {
                // DHCP lease fetch is best-effort; active session sync already succeeded.
            }

            aircoins_audit($pdo, $adminId, 'devices_sync', 'router #' . $routerId . ' synced ' . $upserted . ' device(s)');
            aircoins_flash('success', 'Synced ' . $upserted . ' device(s) from "' . $router['name'] . '".');
        } catch (Throwable $e) {
            aircoins_flash('error', 'Sync failed: ' . $e->getMessage());
        }
        header($back);
        exit;
    }

    // ---- Add device manually -----------------------------------------------
    if ($action === 'add') {
        $mac      = strtoupper(trim((string) ($_POST['mac_address'] ?? '')));
        $ip       = trim((string) ($_POST['ip_address'] ?? ''));
        $hostname = trim((string) ($_POST['hostname'] ?? ''));
        $status   = (string) ($_POST['status'] ?? 'active');
        $sessTime = trim((string) ($_POST['session_time'] ?? ''));

        if ($mac === '') {
            aircoins_flash('error', 'MAC address is required.');
            header($back);
            exit;
        }
        if (!in_array($status, ['active', 'expired', 'blocked'], true)) {
            $status = 'active';
        }
        try {
            $now = time();
            $ins = $pdo->prepare('INSERT INTO devices (mac_address, ip_address, hostname, status, session_time, first_seen, last_seen, created_at, updated_at) VALUES (:mac, :ip, :host, :status, :st, :now, :now2, :now3, :now4)');
            $ins->execute([':mac' => $mac, ':ip' => $ip, ':host' => $hostname, ':status' => $status, ':st' => $sessTime, ':now' => $now, ':now2' => $now, ':now3' => $now, ':now4' => $now]);

            // Create RADIUS user so the device can authenticate.
            $pushMsg = '';
            if ($sessTime !== '') {
                try {
                    $radiusPdo = aircoins_radius_db();
                    aircoins_radius_schema($radiusPdo);
                    $macUser = str_replace(':', '', strtoupper($mac));
                    $sessionSeconds = aircoins_parse_time_to_seconds($sessTime);
                    aircoins_radius_add_user($radiusPdo, $macUser, $macUser, $sessionSeconds, 'device ' . $mac);
                    $pushMsg = ' RADIUS user created (' . $sessTime . ').';
                } catch (Throwable $re) {
                    $pushMsg = ' (RADIUS user creation failed: ' . $re->getMessage() . ')';
                }
            }

            aircoins_audit($pdo, $adminId, 'device_add', 'mac=' . $mac . $pushMsg);
            aircoins_flash('success', 'Device "' . $mac . '" added.' . $pushMsg);
        } catch (Throwable $e) {
            $msg = $e->getMessage();
            if (strpos($msg, 'UNIQUE') !== false || strpos($msg, 'idx_devices_mac') !== false) {
                aircoins_flash('error', 'A device with MAC "' . $mac . '" already exists.');
            } else {
                aircoins_flash('error', 'Add failed: ' . $msg);
            }
        }
        header($back);
        exit;
    }

    // ---- Edit device -------------------------------------------------------
    if ($action === 'edit') {
        $id       = (int) ($_POST['id'] ?? 0);
        $hostname = trim((string) ($_POST['hostname'] ?? ''));
        $status   = (string) ($_POST['status'] ?? 'active');
        $sessTime = trim((string) ($_POST['session_time'] ?? ''));
        $ip       = trim((string) ($_POST['ip_address'] ?? ''));

        if ($id <= 0) {
            aircoins_flash('error', 'Missing device id.');
            header($back);
            exit;
        }
        if (!in_array($status, ['active', 'expired', 'blocked'], true)) {
            $status = 'active';
        }
        try {
            // Fetch current device row to get MAC and router_id.
            $stmt = $pdo->prepare('SELECT * FROM devices WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $id]);
            $device = $stmt->fetch();

            $upd = $pdo->prepare('UPDATE devices SET hostname = :host, ip_address = :ip, status = :status, session_time = :st, updated_at = :now WHERE id = :id');
            $upd->execute([':host' => $hostname, ':ip' => $ip, ':status' => $status, ':st' => $sessTime, ':now' => time(), ':id' => $id]);

            // Push session time to RADIUS as a MAC-auth user.
            $mac = (string) ($device['mac_address'] ?? '');
            $pushMsg = '';
            if ($mac !== '' && $sessTime !== '') {
                try {
                    $radiusPdo = aircoins_radius_db();
                    aircoins_radius_schema($radiusPdo);
                    $macUser = str_replace(':', '', strtoupper($mac));
                    $sessionSeconds = aircoins_parse_time_to_seconds($sessTime);
                    aircoins_radius_add_user($radiusPdo, $macUser, $macUser, $sessionSeconds, 'device ' . $mac);
                    $pushMsg = ' Session time pushed to RADIUS (' . $sessTime . ').';
                } catch (Throwable $re) {
                    $pushMsg = ' (RADIUS push failed: ' . $re->getMessage() . ')';
                }
            }

            aircoins_audit($pdo, $adminId, 'device_edit', 'device #' . $id . $pushMsg);
            aircoins_flash('success', 'Device updated.' . $pushMsg);
        } catch (Throwable $e) {
            aircoins_flash('error', 'Update failed: ' . $e->getMessage());
        }
        header($back);
        exit;
    }

    // ---- Delete device -----------------------------------------------------
    if ($action === 'delete') {
        $id  = (int) ($_POST['id'] ?? 0);
        $mac = (string) ($_POST['mac'] ?? '');
        if ($id <= 0) {
            aircoins_flash('error', 'Missing device id.');
            header($back);
            exit;
        }
        try {
            $del = $pdo->prepare('DELETE FROM devices WHERE id = :id');
            $del->execute([':id' => $id]);
            aircoins_audit($pdo, $adminId, 'device_delete', 'device #' . $id . ' mac=' . $mac);
            aircoins_flash('success', 'Device "' . ($mac !== '' ? $mac : '#' . $id) . '" deleted.');
        } catch (Throwable $e) {
            aircoins_flash('error', 'Delete failed: ' . $e->getMessage());
        }
        header($back);
        exit;
    }

    // ---- Kick session ------------------------------------------------------
    if ($action === 'kick') {
        $sessId   = (string) ($_POST['session_id'] ?? '');
        $mac      = (string) ($_POST['mac'] ?? '');
        if (!$router || $sessId === '') {
            aircoins_flash('error', 'Missing router or session id.');
            header($back);
            exit;
        }
        try {
            $client = aircoins_router_client($router);
            $client->kickSession($sessId);
            aircoins_audit($pdo, $adminId, 'device_kick', 'router #' . $routerId . ' session ' . $sessId . ' mac=' . $mac);
            aircoins_flash('success', 'Disconnected ' . ($mac !== '' ? $mac : 'session') . '.');
        } catch (Throwable $e) {
            aircoins_flash('error', 'Kick failed: ' . $e->getMessage());
        }
        header($back);
        exit;
    }

    // ---- Add time to device session ----------------------------------------
    if ($action === 'add_time') {
        $id      = (int) ($_POST['id'] ?? 0);
        $mac     = (string) ($_POST['mac'] ?? '');
        $days    = max(0, (int) ($_POST['add_days'] ?? 0));
        $hours   = max(0, (int) ($_POST['add_hours'] ?? 0));
        $minutes = max(0, (int) ($_POST['add_minutes'] ?? 0));

        if ($id <= 0 || $mac === '') {
            aircoins_flash('error', 'Missing device.');
            header($back);
            exit;
        }
        $addSeconds = ($days * 86400) + ($hours * 3600) + ($minutes * 60);
        if ($addSeconds <= 0) {
            aircoins_flash('error', 'Enter a time amount to add.');
            header($back);
            exit;
        }

        try {
            // MAC without colons, UPPERCASE — matches what the portal sends.
            $macUser = str_replace(':', '', strtoupper($mac));

            // Extend the RADIUS user session by the added seconds.
            $radiusPdo = aircoins_radius_db();
            aircoins_radius_schema($radiusPdo);
            $newTotalSec = aircoins_radius_extend_session($radiusPdo, $macUser, $addSeconds);
            $newLimit = aircoins_seconds_to_time($newTotalSec);

            // Update DB session_time too.
            $newLimitDisplay = aircoins_seconds_to_time($addSeconds);
            $pdo->prepare('UPDATE devices SET session_time = :st, updated_at = :now WHERE id = :id')
                ->execute([':st' => $newLimit, ':now' => time(), ':id' => $id]);

            aircoins_audit($pdo, $adminId, 'device_add_time', 'device #' . $id . ' mac=' . $mac . ' added=' . $newLimit);
            aircoins_flash('success', 'Added ' . $newLimit . ' to ' . $mac . '. Device must reconnect to the captive portal to authenticate.');
        } catch (Throwable $e) {
            aircoins_flash('error', 'Add time failed: ' . $e->getMessage());
        }
        header($back);
        exit;
    }

    aircoins_flash('error', 'Unknown action.');
    header($back);
    exit;
}

// ---- Load devices from DB --------------------------------------------------
$devices = [];
try {
    $devices = $pdo->query('SELECT d.*, r.name AS router_name FROM devices d LEFT JOIN routers r ON d.router_id = r.id ORDER BY d.last_seen DESC, d.mac_address ASC')->fetchAll();
} catch (Throwable $e) {
    $devices = [];
}

// ---- Classify devices as online/offline using MikroTik active sessions -----
$activeMacs = [];
$activeByRouter = [];
if ($router) {
    try {
        $client   = aircoins_router_client($router);
        $sessions = $client->activeSessions();
        foreach ($sessions as $s) {
            $m = strtoupper((string) ($s['mac'] ?? ''));
            if ($m !== '') {
                $activeMacs[$m] = true;
                $activeByRouter[$m] = [
                    'router_id'    => (int) $router['id'],
                    'session_id'   => (string) ($s['.id'] ?? ''),
                    'uptime'       => (string) ($s['uptime'] ?? ''),
                    'limit-uptime' => (string) ($s['limit-uptime'] ?? ''),
                ];
            }
        }
    } catch (Throwable $e) {
        // Router unreachable — skip.
    }
}

aircoins_header('Devices', 'devices');
?>

<!-- Sync bar -->
<div class="card" style="margin-bottom:20px">
  <div class="card__body">
    <form method="post" action="devices.php" class="row row--between" style="gap:14px">
      <?php echo csrf_field(); ?>
      <input type="hidden" name="action" value="sync">
      <p class="hint" style="margin:0">Sync devices from the selected router's active sessions and DHCP leases.</p>
      <button class="btn btn--primary btn--sm" type="submit" <?php echo !$router ? 'disabled' : ''; ?>>
        <svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><path fill="currentColor" d="M12 4V1L8 5l4 4V6c3.31 0 6 2.69 6 6 0 1.01-.25 1.97-.7 2.8l1.46 1.46A7.93 7.93 0 0 0 20 12c0-4.42-3.58-8-8-8Zm0 14c-3.31 0-6-2.69-6-6 0-1.01.25-1.97.7-2.8L5.24 7.74A7.93 7.93 0 0 0 4 12c0 4.42 3.58 8 8 8v3l4-4-4-4v3Z"/></svg>
        Sync devices
      </button>
    </form>
  </div>
</div>

<div class="grid grid--2" style="margin-bottom:20px;align-items:start">
  <!-- Add device form -->
  <div class="card">
    <div class="card__head"><h2 class="card__title">Add device</h2></div>
    <div class="card__body">
      <form method="post" action="devices.php" autocomplete="off">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="add">
        <div class="field">
          <label for="d-mac">MAC address</label>
          <input class="input input--mono" id="d-mac" name="mac_address" type="text" required placeholder="AA:BB:CC:DD:EE:FF">
        </div>
        <div class="form-grid">
          <div class="field">
            <label for="d-ip">IP address</label>
            <input class="input input--mono" id="d-ip" name="ip_address" type="text" placeholder="10.0.0.x">
          </div>
          <div class="field">
            <label for="d-host">Hostname</label>
            <input class="input" id="d-host" name="hostname" type="text" placeholder="optional">
          </div>
        </div>
        <div class="form-grid">
          <div class="field">
            <label for="d-status">Status</label>
            <select class="select" id="d-status" name="status">
              <option value="active">Active</option>
              <option value="expired">Expired</option>
              <option value="blocked">Blocked</option>
            </select>
          </div>
          <div class="field">
            <label for="d-st">Session time</label>
            <input class="input input--mono" id="d-st" name="session_time" type="text" placeholder="e.g. 1h30m">
          </div>
        </div>
        <button class="btn btn--primary btn--block" type="submit">Add device</button>
      </form>
    </div>
  </div>

  <!-- Stats card -->
  <div class="card">
    <div class="card__head"><h2 class="card__title">Overview</h2></div>
    <div class="card__body">
      <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:16px;text-align:center">
        <div>
          <div style="font-family:var(--font-mono);font-size:28px;font-weight:700;color:var(--teal-deep)"><?php echo count($devices); ?></div>
          <div class="hint">Total devices</div>
        </div>
        <div>
          <div style="font-family:var(--font-mono);font-size:28px;font-weight:700;color:var(--ok)"><?php echo count($activeMacs); ?></div>
          <div class="hint">Online now</div>
        </div>
        <div>
          <div style="font-family:var(--font-mono);font-size:28px;font-weight:700;color:var(--bad)"><?php
            echo count(array_filter($devices, fn($d) => ($d['status'] ?? '') === 'blocked'));
          ?></div>
          <div class="hint">Blocked</div>
        </div>
        <div>
          <div style="font-family:var(--font-mono);font-size:28px;font-weight:700;color:var(--warn)"><?php
            echo count(array_filter($devices, fn($d) => ($d['status'] ?? '') === 'expired'));
          ?></div>
          <div class="hint">Expired</div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Devices table -->
<div class="card">
  <div class="card__head">
    <h2 class="card__title">All devices</h2>
    <div class="spacer"></div>
    <span class="hint"><?php echo count($devices); ?> device(s)</span>
  </div>
  <div class="card__body card__body--flush">
    <?php if ($devices === []): ?>
      <div class="empty">
        <svg viewBox="0 0 24 24" width="40" height="40" aria-hidden="true"><path fill="currentColor" d="M20 18c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2H4c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2H0v2h24v-2h-4ZM4 6h16v10H4V6Z"/></svg>
        <div>No devices yet. Sync from a router or add one manually.</div>
      </div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="data">
          <thead>
            <tr>
              <th>MAC</th>
              <th>IP</th>
              <th>Hostname</th>
              <th>User</th>
              <th>Session</th>
              <th>Time Left</th>
              <th>Router</th>
              <th>Status</th>
              <th>Last seen</th>
              <th class="actions">Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($devices as $d):
            $mac     = (string) ($d['mac_address'] ?? '');
            $isOnline = isset($activeMacs[strtoupper($mac)]);
            $dbStatus = (string) ($d['status'] ?? 'active');
          ?>
            <tr>
              <td class="mono"><strong><?php echo e($mac); ?></strong></td>
              <td class="mono"><?php echo e((string) ($d['ip_address'] ?? '')); ?></td>
              <td><?php echo e((string) ($d['hostname'] ?? '—')); ?></td>
              <td><?php echo e((string) ($d['user'] ?? '—')); ?></td>
              <td class="mono"><?php echo e((string) ($d['session_time'] ?? '—')); ?></td>
              <td class="mono">
                <?php if ($isOnline && isset($activeByRouter[strtoupper($mac)])):
                  $sessInfo = $activeByRouter[strtoupper($mac)];
                  $limitSec = aircoins_parse_time_to_seconds($sessInfo['limit-uptime']);
                  $uptimeSec = aircoins_parse_time_to_seconds($sessInfo['uptime']);
                  $remainSec = $limitSec > 0 ? $limitSec - $uptimeSec : 0;
                ?>
                  <?php if ($limitSec > 0):
                    $h = intdiv($remainSec, 3600);
                    $m = intdiv($remainSec % 3600, 60);
                    $s = $remainSec % 60;
                  ?>
                    <span class="countdown <?php echo $remainSec <= 0 ? 'countdown--expired' : ($remainSec < 300 ? 'countdown--warn' : ''); ?>"
                          data-limit="<?php echo $limitSec; ?>"
                          data-uptime="<?php echo $uptimeSec; ?>"
                          data-load="<?php echo time(); ?>">
                      <?php echo sprintf('%02d:%02d:%02d', $h, $m, $s); ?>
                    </span>
                  <?php else: ?>
                    <span class="hint">unlimited</span>
                  <?php endif; ?>
                <?php else: ?>
                  <span class="hint">—</span>
                <?php endif; ?>
              </td>
              <td class="hint"><?php echo e((string) ($d['router_name'] ?? '—')); ?></td>
              <td>
                <?php if ($isOnline): ?>
                  <span class="badge badge--online pulse">ONLINE</span>
                <?php elseif ($dbStatus === 'blocked'): ?>
                  <span class="badge badge--offline">BLOCKED</span>
                <?php elseif ($dbStatus === 'expired'): ?>
                  <span class="badge badge--warn">EXPIRED</span>
                <?php else: ?>
                  <span class="badge badge--idle">OFFLINE</span>
                <?php endif; ?>
              </td>
              <td class="mono hint"><?php
                $lastSeen = (int) ($d['last_seen'] ?? 0);
                echo $lastSeen > 0 ? date('M j H:i', $lastSeen) : '—';
              ?></td>
              <td class="actions">
                <div class="btn-group">
                  <button class="btn btn--ghost btn--sm" type="button" data-edit-device
                    data-id="<?php echo (int) $d['id']; ?>"
                    data-mac="<?php echo e($mac); ?>"
                    data-ip="<?php echo e((string) ($d['ip_address'] ?? '')); ?>"
                    data-hostname="<?php echo e((string) ($d['hostname'] ?? '')); ?>"
                    data-status="<?php echo e($dbStatus); ?>"
                    data-session="<?php echo e((string) ($d['session_time'] ?? '')); ?>">Edit</button>
                  <button class="btn btn--ghost btn--sm" type="button" data-add-time
                    data-id="<?php echo (int) $d['id']; ?>"
                    data-mac="<?php echo e($mac); ?>">+Time</button>
                  <?php if ($isOnline && isset($activeByRouter[strtoupper($mac)])):
                    $info = $activeByRouter[strtoupper($mac)];
                  ?>
                    <form method="post" action="devices.php" style="display:inline">
                      <?php echo csrf_field(); ?>
                      <input type="hidden" name="action" value="kick">
                      <input type="hidden" name="session_id" value="<?php echo e($info['session_id']); ?>">
                      <input type="hidden" name="mac" value="<?php echo e($mac); ?>">
                      <button class="btn btn--danger btn--sm" type="submit"
                              data-confirm="Disconnect <?php echo e($mac); ?> now?">Kick</button>
                    </form>
                  <?php endif; ?>
                  <form method="post" action="devices.php" style="display:inline">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?php echo (int) $d['id']; ?>">
                    <input type="hidden" name="mac" value="<?php echo e($mac); ?>">
                    <button class="btn btn--danger btn--sm" type="submit"
                            data-confirm="Delete device <?php echo e($mac); ?> from the database?">Del</button>
                  </form>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- Edit modal -->
<div class="modal" id="editModal" hidden>
  <div class="modal__scrim" data-modal-close></div>
  <div class="modal__panel">
    <div class="modal__head">
      <h3>Edit device</h3>
      <button class="modal__x" type="button" data-modal-close>&times;</button>
    </div>
    <form method="post" action="devices.php">
      <?php echo csrf_field(); ?>
      <input type="hidden" name="action" value="edit">
      <input type="hidden" name="id" id="edit-id">
      <div class="modal__body">
        <div class="field">
          <label>MAC address</label>
          <input class="input input--mono" id="edit-mac" type="text" readonly>
        </div>
        <div class="form-grid">
          <div class="field">
            <label for="edit-ip">IP address</label>
            <input class="input input--mono" id="edit-ip" name="ip_address" type="text">
          </div>
          <div class="field">
            <label for="edit-hostname">Hostname</label>
            <input class="input" id="edit-hostname" name="hostname" type="text">
          </div>
        </div>
        <div class="form-grid">
          <div class="field">
            <label for="edit-status">Status</label>
            <select class="select" id="edit-status" name="status">
              <option value="active">Active</option>
              <option value="expired">Expired</option>
              <option value="blocked">Blocked</option>
            </select>
          </div>
          <div class="field">
            <label for="edit-session">Session time</label>
            <input class="input input--mono" id="edit-session" name="session_time" type="text" placeholder="e.g. 1h30m">
          </div>
        </div>
      </div>
      <div class="modal__foot">
        <button class="btn btn--ghost" type="button" data-modal-close>Cancel</button>
        <button class="btn btn--primary" type="submit">Save changes</button>
      </div>
    </form>
  </div>
</div>

<!-- Add Time modal -->
<div class="modal" id="addTimeModal" hidden>
  <div class="modal__scrim" data-modal-close></div>
  <div class="modal__panel">
    <div class="modal__head">
      <h3>Add session time</h3>
      <button class="modal__x" type="button" data-modal-close>&times;</button>
    </div>
    <form method="post" action="devices.php">
      <?php echo csrf_field(); ?>
      <input type="hidden" name="action" value="add_time">
      <input type="hidden" name="id" id="at-id">
      <input type="hidden" name="mac" id="at-mac">
      <div class="modal__body">
        <div class="field">
          <label>Device</label>
          <input class="input input--mono" id="at-mac-display" type="text" readonly>
        </div>
        <div class="form-grid form-grid--3">
          <div class="field">
            <label for="at-days">Days</label>
            <input class="input input--mono" id="at-days" name="add_days" type="number" min="0" max="365" value="0" step="1">
          </div>
          <div class="field">
            <label for="at-hours">Hours</label>
            <input class="input input--mono" id="at-hours" name="add_hours" type="number" min="0" max="23" value="0" step="1">
          </div>
          <div class="field">
            <label for="at-mins">Minutes</label>
            <input class="input input--mono" id="at-mins" name="add_minutes" type="number" min="0" max="59" value="0" step="1">
          </div>
        </div>
        <p class="hint" style="margin-top:4px">Time will be added to the device's current session limit in RADIUS.</p>
      </div>
      <div class="modal__foot">
        <button class="btn btn--ghost" type="button" data-modal-close>Cancel</button>
        <button class="btn btn--primary" type="submit">Add time</button>
      </div>
    </form>
  </div>
</div>

<script>
(function() {
  /* ---- Edit modal ---- */
  var editModal = document.getElementById('editModal');
  if (editModal) {
    function openEditModal() { editModal.hidden = false; }
    function closeEditModal() { editModal.hidden = true; }

    document.querySelectorAll('[data-edit-device]').forEach(function(btn) {
      btn.addEventListener('click', function() {
        document.getElementById('edit-id').value = btn.dataset.id;
        document.getElementById('edit-mac').value = btn.dataset.mac;
        document.getElementById('edit-ip').value = btn.dataset.ip;
        document.getElementById('edit-hostname').value = btn.dataset.hostname;
        document.getElementById('edit-status').value = btn.dataset.status;
        document.getElementById('edit-session').value = btn.dataset.session;
        openEditModal();
      });
    });

    editModal.querySelectorAll('[data-modal-close]').forEach(function(el) {
      el.addEventListener('click', closeEditModal);
    });
  }

  /* ---- Add Time modal ---- */
  var atModal = document.getElementById('addTimeModal');
  if (atModal) {
    function openAtModal() { atModal.hidden = false; }
    function closeAtModal() { atModal.hidden = true; }

    document.querySelectorAll('[data-add-time]').forEach(function(btn) {
      btn.addEventListener('click', function() {
        document.getElementById('at-id').value = btn.dataset.id;
        document.getElementById('at-mac').value = btn.dataset.mac;
        document.getElementById('at-mac-display').value = btn.dataset.mac;
        // Reset fields
        document.getElementById('at-days').value = 0;
        document.getElementById('at-hours').value = 0;
        document.getElementById('at-mins').value = 0;
        openAtModal();
      });
    });

    atModal.querySelectorAll('[data-modal-close]').forEach(function(el) {
      el.addEventListener('click', closeAtModal);
    });
  }

  /* ---- Realtime countdown ---- */
  var countdowns = document.querySelectorAll('.countdown[data-limit]');
  if (countdowns.length) {
    function pad(n) { return n < 10 ? '0' + n : '' + n; }
    function tickCountdown(el) {
      var limit   = parseInt(el.getAttribute('data-limit'), 10) || 0;
      var uptime  = parseInt(el.getAttribute('data-uptime'), 10) || 0;
      var loadTs  = parseInt(el.getAttribute('data-load'), 10) || 0;
      if (limit <= 0) return;
      var now = Math.floor(Date.now() / 1000);
      var elapsed = loadTs > 0 ? (now - loadTs) : 0;
      var currentUptime = uptime + elapsed;
      var remain = limit - currentUptime;
      if (remain <= 0) {
        el.textContent = '00:00:00';
        el.classList.remove('countdown--warn');
        el.classList.add('countdown--expired');
        return;
      }
      var h = Math.floor(remain / 3600);
      var m = Math.floor((remain % 3600) / 60);
      var s = remain % 60;
      el.textContent = pad(h) + ':' + pad(m) + ':' + pad(s);
      // Update warning state
      if (remain < 300) {
        el.classList.add('countdown--warn');
      } else {
        el.classList.remove('countdown--warn');
      }
    }
    // Initial tick
    countdowns.forEach(function(el) { tickCountdown(el); });
    // Tick every second
    setInterval(function() {
      countdowns.forEach(function(el) { tickCountdown(el); });
    }, 1000);
  }
})();
</script>

<?php aircoins_footer(); ?>
