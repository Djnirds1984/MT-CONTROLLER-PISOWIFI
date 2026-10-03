<?php
/**
 * AIRCOINS NETFI — Vendo Setup page.
 *
 * Manages NodeMCU ESP8266 coin-slot vending machines in a centralized system.
 * Auto-discovers pending vendo devices from DHCP leases, accepts them (making
 * their IP static, adding bypass/walled-garden rules), and tracks them in the
 * vendo_devices database table.
 *
 * Accepted devices are shown as cards. Clicking "Edit" opens a modal with:
 *   - Device name, coin pulse pin, debounce settings
 *   - Pricing Matrix: dynamic rows of (coins → time value + unit)
 *
 * Save is via AJAX (JSON response). Accept/Remove are standard POST-Redirect-GET.
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

    // ---- save_settings: AJAX save device + rates --------------------------
    if ($action === 'save_settings') {
        header('Content-Type: application/json; charset=utf-8');

        $deviceName    = trim((string) ($_POST['device_name'] ?? ''));
        $coinPin       = (int) ($_POST['coin_pin'] ?? 4);
        $debounceMs    = (int) ($_POST['debounce_ms'] ?? 150);

        if ($coinPin < 0 || $coinPin > 16) $coinPin = 4;
        if ($debounceMs < 10 || $debounceMs > 5000) $debounceMs = 150;

        // Parse rates from JSON
        $ratesJson = (string) ($_POST['rates'] ?? '[]');
        $rates = [];
        $decoded = json_decode($ratesJson, true);
        if (is_array($decoded)) {
            foreach ($decoded as $r) {
                $coins = max(1, (int) ($r['coins'] ?? 1));
                $timeValue = max(1, (int) ($r['time_value'] ?? 15));
                $timeUnit = in_array((string) ($r['time_unit'] ?? ''), ['MIN', 'HRS'], true)
                    ? (string) $r['time_unit'] : 'MIN';
                $rates[] = ['coins' => $coins, 'time_value' => $timeValue, 'time_unit' => $timeUnit];
            }
        }

        try {
            // Update device settings
            $upd = $pdo->prepare(
                'UPDATE vendo_devices SET device_name = :dn, coin_pin = :cp, '
                . 'debounce_ms = :db WHERE id = :id'
            );
            $upd->execute([
                ':dn' => $deviceName,
                ':cp' => $coinPin,
                ':db' => $debounceMs,
                ':id' => $vendoId,
            ]);

            // Replace rates
            $del = $pdo->prepare('DELETE FROM vendo_rates WHERE vendo_id = :id');
            $del->execute([':id' => $vendoId]);

            $ins = $pdo->prepare(
                'INSERT INTO vendo_rates (vendo_id, coins, time_value, time_unit) '
                . 'VALUES (:vid, :coins, :tv, :tu)'
            );
            foreach ($rates as $r) {
                $ins->execute([
                    ':vid' => $vendoId,
                    ':coins' => $r['coins'],
                    ':tv' => $r['time_value'],
                    ':tu' => $r['time_unit'],
                ]);
            }

            aircoins_audit($pdo, $_SESSION['admin_id'] ?? null, 'vendo_save_settings',
                'vendo #' . $vendoId . ' rates=' . count($rates));

            echo json_encode(['ok' => true, 'message' => 'Settings saved.']);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'message' => 'Save failed: ' . $e->getMessage()]);
        }
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
                continue;
            }

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

// Load ALL accepted vendos from DB (with full settings + rates).
try {
    $accStmt = $pdo->query(
        "SELECT * FROM vendo_devices WHERE status = 'accepted' ORDER BY device_name ASC, id ASC"
    );
    while ($row = $accStmt->fetch()) {
        $accepted[] = $row;
    }
} catch (Throwable $e) { /* empty */ }

// Load rates for all accepted vendos.
$ratesByVendo = [];
if ($accepted !== []) {
    try {
        $ids = array_column($accepted, 'id');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rateStmt = $pdo->prepare(
            "SELECT * FROM vendo_rates WHERE vendo_id IN ($placeholders) ORDER BY coins ASC"
        );
        $rateStmt->execute($ids);
        while ($row = $rateStmt->fetch()) {
            $ratesByVendo[(int) $row['vendo_id']][] = $row;
        }
    } catch (Throwable $e) { /* empty */ }
}

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
      <p class="lead">Select a router from the top bar to discover vendo devices.</p>
    <?php elseif ($pending === []): ?>
      <p class="lead">No pending vendo devices found. Make sure your NodeMCU is connected and has obtained a DHCP lease.</p>
    <?php else: ?>
      <div class="table-wrap">
        <table class="data">
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
                <td class="mono"><?php echo e($p['mac_address']); ?></td>
                <td><?php echo e($p['ip_address']); ?></td>
                <td><?php echo e($p['hostname'] ?: '—'); ?></td>
                <td class="actions">
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
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- Accepted Devices — Card Layout -->
<div class="card">
  <div class="card__head">
    <h2 class="card__title">Accepted Vendo Devices</h2>
    <span class="card__sub">Click Edit to configure device settings and pricing matrix</span>
  </div>
  <div class="card__body">
    <?php if ($accepted === []): ?>
      <div class="empty">No accepted vendo devices yet. Accept a pending device above to get started.</div>
    <?php else: ?>
      <div class="grid grid--cards">
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
            $aRates = $ratesByVendo[$aId] ?? [];
          ?>
          <div class="rcard">
            <div class="rcard__top">
              <div class="rcard__id">
                <div class="rcard__name"><?php echo e($aName); ?></div>
                <div class="rcard__host"><?php echo e($aMac); ?></div>
              </div>
              <span class="badge badge--online">ONLINE</span>
            </div>
            <div class="rcard__metrics">
              <div class="metric">
                <div class="metric__k">IP Address</div>
                <div class="metric__v" style="font-size:15px;"><?php echo e($aIp); ?></div>
              </div>
              <div class="metric">
                <div class="metric__k">Rates</div>
                <div class="metric__v" style="font-size:14px;">
                  <?php if ($aRates !== []): ?>
                    <?php
                    $rateLabels = [];
                    foreach ($aRates as $r) {
                        $rateLabels[] = (int) $r['coins'] . ' coin' . ((int)$r['coins'] > 1 ? 's' : '')
                            . ' = ' . (int) $r['time_value'] . ' ' . strtolower($r['time_unit']);
                    }
                    echo e(implode(', ', $rateLabels));
                    ?>
                  <?php else: ?>
                    <span style="color:var(--ink-faint)">No rates configured</span>
                  <?php endif; ?>
                </div>
              </div>
            </div>
            <div style="padding:14px 20px;display:flex;gap:8px;">
              <button class="btn btn--primary btn--sm" onclick="openEditModal(<?php echo $aId; ?>)">Edit Settings</button>
              <form method="post" action="vendo.php" style="display:inline;margin-left:auto;" data-confirm="Remove this vendo? This will delete the static lease, binding, and walled-garden rules.">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="remove">
                <input type="hidden" name="vendo_id" value="<?php echo $aId; ?>">
                <input type="hidden" name="router_id" value="<?php echo $routerId; ?>">
                <button type="submit" class="btn btn--danger btn--sm">Unassign</button>
              </form>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- Edit Modal -->
<div class="modal modal--wide" id="editModal" hidden>
  <div class="modal__scrim" onclick="closeEditModal()"></div>
  <div class="modal__panel">
    <div class="modal__head">
      <h3>Configure Node</h3>
      <button class="modal__x" onclick="closeEditModal()">&times;</button>
    </div>
    <div class="modal__body">
      <form id="editForm">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="save_settings">
        <input type="hidden" name="vendo_id" id="editVendoId" value="">

        <div class="field">
          <label>Display Name</label>
          <input class="input" type="text" name="device_name" id="editDeviceName" placeholder="e.g. Vendo 1 - Lobby">
        </div>

        <div class="form-grid">
          <div class="field">
            <label>Coin Pulse Pin</label>
            <select class="select" name="coin_pin" id="editCoinPin">
              <?php foreach ([0=>'D0 (Flash)', 2=>'D2 (Coin)', 4=>'D4 (LED)', 5=>'D1', 12=>'D6', 13=>'D7', 14=>'D5', 15=>'D8', 16=>'D3 (Setup)'] as $gpio => $lbl): ?>
                <option value="<?php echo $gpio; ?>"><?php echo e($lbl); ?> — GPIO<?php echo $gpio; ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label>Pulse Debounce (ms)</label>
            <input class="input" type="number" name="debounce_ms" id="editDebounce" min="10" max="5000" step="10" value="150">
            <span class="hint">Minimum time between coin pulses. Increase if coins are double-counting. Default: 150ms.</span>
          </div>
        </div>

        <div class="section-title" style="margin-top:8px;">Pricing Matrix <button type="button" class="btn btn--ghost btn--sm" onclick="addRateRow()" style="margin-left:auto;font-size:11px;">+ Add Rate</button></div>
        <div id="ratesContainer"></div>
        <div id="noRatesMsg" class="hint" style="padding:12px 0;text-align:center;">No rates configured. Click "+ Add Rate" to add one.</div>
      </form>
    </div>
    <div class="modal__foot">
      <button class="btn btn--ghost" onclick="closeEditModal()">Cancel</button>
      <button class="btn btn--primary" id="saveBtn" onclick="saveSettings()">Save Configuration</button>
    </div>
  </div>
</div>

<script>
// Rate row template
var rateRowId = 0;

function addRateRow(coins, timeValue, timeUnit) {
    coins = coins || 1;
    timeValue = timeValue || 15;
    timeUnit = timeUnit || 'MIN';
    rateRowId++;
    var id = rateRowId;
    var html = '<div class="rate-row" data-id="' + id + '" style="border:1px solid var(--line);border-radius:var(--r-sm);padding:12px 14px;margin-bottom:10px;background:var(--card-alt);">';
    html += '<div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">';
    html += '<div style="flex:1;min-width:80px;">';
    html += '<label style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--ink-faint);display:block;margin-bottom:4px;">Coins</label>';
    html += '<input type="number" class="input rate-coins" value="' + coins + '" min="1" max="100" style="padding:8px 10px;font-size:14px;">';
    html += '</div>';
    html += '<div style="flex:1;min-width:80px;">';
    html += '<label style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--ink-faint);display:block;margin-bottom:4px;">Time</label>';
    html += '<input type="number" class="input rate-time" value="' + timeValue + '" min="1" max="9999" style="padding:8px 10px;font-size:14px;">';
    html += '</div>';
    html += '<div style="min-width:80px;">';
    html += '<label style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--ink-faint);display:block;margin-bottom:4px;">Unit</label>';
    html += '<select class="select rate-unit" style="padding:8px 10px;font-size:14px;">';
    html += '<option value="MIN"' + (timeUnit === 'MIN' ? ' selected' : '') + '>MIN</option>';
    html += '<option value="HRS"' + (timeUnit === 'HRS' ? ' selected' : '') + '>HRS</option>';
    html += '</select>';
    html += '</div>';
    html += '<div style="display:flex;align-items:flex-end;padding-bottom:1px;">';
    html += '<button type="button" class="btn btn--danger btn--sm" onclick="removeRateRow(' + id + ')" title="Remove rate">&times;</button>';
    html += '</div>';
    html += '</div></div>';
    document.getElementById('ratesContainer').insertAdjacentHTML('beforeend', html);
    updateNoRatesMsg();
}

function removeRateRow(id) {
    var row = document.querySelector('.rate-row[data-id="' + id + '"]');
    if (row) row.remove();
    updateNoRatesMsg();
}

function updateNoRatesMsg() {
    var rows = document.querySelectorAll('.rate-row');
    document.getElementById('noRatesMsg').style.display = rows.length === 0 ? 'block' : 'none';
}

// Vendo data for modal
var vendoData = <?php
    $jsonData = [];
    foreach ($accepted as $a) {
        $aId = (int) $a['id'];
        $aName = (string) ($a['device_name'] ?? '');
        if ($aName === '') $aName = 'Vendo ' . strtoupper(substr((string) $a['mac_address'], -5));
        $jsonData[$aId] = [
            'name' => $aName,
            'coin_pin' => (int) ($a['coin_pin'] ?? 4),
            'debounce_ms' => (int) ($a['debounce_ms'] ?? 150),
            'rates' => $ratesByVendo[$aId] ?? [],
        ];
    }
    echo json_encode($jsonData);
?>;

function openEditModal(vendoId) {
    var data = vendoData[vendoId];
    if (!data) return;
    document.getElementById('editVendoId').value = vendoId;
    document.getElementById('editDeviceName').value = data.name;
    document.getElementById('editCoinPin').value = data.coin_pin;
    document.getElementById('editDebounce').value = data.debounce_ms;
    // Clear existing rate rows
    document.getElementById('ratesContainer').innerHTML = '';
    rateRowId = 0;
    // Add rate rows from data
    if (data.rates && data.rates.length > 0) {
        for (var i = 0; i < data.rates.length; i++) {
            var r = data.rates[i];
            addRateRow(parseInt(r.coins), parseInt(r.time_value), r.time_unit);
        }
    } else {
        addRateRow(1, 15, 'MIN');
    }
    document.getElementById('editModal').hidden = false;
    document.body.style.overflow = 'hidden';
}

function closeEditModal() {
    document.getElementById('editModal').hidden = true;
    document.body.style.overflow = '';
}

function saveSettings() {
    var form = document.getElementById('editForm');
    var formData = new FormData(form);

    // Collect rates
    var rates = [];
    var rows = document.querySelectorAll('.rate-row');
    for (var i = 0; i < rows.length; i++) {
        var row = rows[i];
        rates.push({
            coins: parseInt(row.querySelector('.rate-coins').value) || 1,
            time_value: parseInt(row.querySelector('.rate-time').value) || 15,
            time_unit: row.querySelector('.rate-unit').value
        });
    }
    formData.set('rates', JSON.stringify(rates));

    var btn = document.getElementById('saveBtn');
    btn.disabled = true;
    btn.textContent = 'Saving...';

    fetch('vendo.php', {
        method: 'POST',
        body: formData
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        btn.disabled = false;
        btn.textContent = 'Save Configuration';
        if (data.ok) {
            closeEditModal();
            location.reload();
        } else {
            alert('Save failed: ' + (data.message || 'Unknown error'));
        }
    })
    .catch(function(err) {
        btn.disabled = false;
        btn.textContent = 'Save Configuration';
        alert('Save failed: ' + err);
    });
}

// Close modal on Escape
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeEditModal();
});
</script>

<?php aircoins_footer();
