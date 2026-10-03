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
            aircoins_audit($pdo, $adminId, 'device_add', 'mac=' . $mac);
            aircoins_flash('success', 'Device "' . $mac . '" added.');
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

            // Push session time to router as a hotspot user (MAC auth).
            $mac = (string) ($device['mac_address'] ?? '');
            $routerId = (int) ($device['router_id'] ?? 0);
            $pushMsg = '';
            if ($mac !== '' && $routerId > 0 && $sessTime !== '') {
                $router = aircoins_get_router($pdo, $routerId);
                if ($router) {
                    try {
                        $client = aircoins_router_client($router);
                        // MAC without colons as username (matches MikroTik convention).
                        $macUser = str_replace(':', '', $mac);
                        // Try to create the user; if it already exists, delete+recreate.
                        try {
                            $client->addHotspotUser($macUser, $macUser, '', 'device ' . $mac, $sessTime);
                        } catch (Throwable $dup) {
                            // User likely exists — find and delete it, then recreate.
                            $users = $client->hotspotUsers();
                            foreach ($users as $eu) {
                                if ((string) ($eu['name'] ?? '') === $macUser) {
                                    $client->deleteHotspotUser((string) ($eu['.id'] ?? ''));
                                    break;
                                }
                            }
                            $client->addHotspotUser($macUser, $macUser, '', 'device ' . $mac, $sessTime);
                        }
                        $pushMsg = ' Session time pushed to router (' . $sessTime . ').';
                    } catch (Throwable $re) {
                        $pushMsg = ' (Router push failed: ' . $re->getMessage() . ')';
                    }
                } else {
                    $pushMsg = ' (Router #' . $routerId . ' not found)';
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
                $activeByRouter[$m] = ['router_id' => (int) $router['id'], 'session_id' => (string) ($s['.id'] ?? ''), 'uptime' => (string) ($s['uptime'] ?? '')];
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

<script>
(function() {
  var modal = document.getElementById('editModal');
  if (!modal) return;

  function openModal() { modal.hidden = false; }
  function closeModal() { modal.hidden = true; }

  document.querySelectorAll('[data-edit-device]').forEach(function(btn) {
    btn.addEventListener('click', function() {
      document.getElementById('edit-id').value = btn.dataset.id;
      document.getElementById('edit-mac').value = btn.dataset.mac;
      document.getElementById('edit-ip').value = btn.dataset.ip;
      document.getElementById('edit-hostname').value = btn.dataset.hostname;
      document.getElementById('edit-status').value = btn.dataset.status;
      document.getElementById('edit-session').value = btn.dataset.session;
      openModal();
    });
  });

  modal.querySelectorAll('[data-modal-close]').forEach(function(el) {
    el.addEventListener('click', closeModal);
  });
})();
</script>

<?php aircoins_footer(); ?>
