<?php
/**
 * AIRCOINS NETFI — hotspot management.
 *
 * Pick a router, then manage its hotspot across three tabs:
 *   Users           — list, add single user, bulk voucher generator, delete.
 *   Active Sessions — list live sessions and kick (disconnect) any of them.
 *   Profiles        — list, create, delete hotspot user profiles.
 *
 * All client calls are wrapped in try/catch and surfaced as a friendly banner.
 * Every POST is CSRF-verified; add/delete/kick/voucher/profile actions are audited.
 * Router passwords are only ever handled inside the factory (never displayed).
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

/**
 * Generate an unambiguous voucher code (no 0/O/1/I/l).
 *
 * @param string $prefix Prefix (upper-cased, alnum only).
 * @param int    $len    Length of the random suffix.
 * @return string e.g. "AIR7K9QM2"
 */
function aircoins_voucher_code(string $prefix, int $len): string
{
    $alpha = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    $max = strlen($alpha) - 1;
    $code = '';
    for ($i = 0; $i < $len; $i++) {
        $code .= $alpha[random_int(0, $max)];
    }
    return $prefix . $code;
}

/**
 * Convert minutes to a MikroTik-friendly time string.
 *
 * Examples: 10 => "10m", 60 => "1h", 90 => "1h30m", 1440 => "1d".
 *
 * @param int $minutes Total minutes (>= 0).
 * @return string MikroTik time value, empty when $minutes <= 0.
 */
function aircoins_minutes_to_time(int $minutes): string
{
    if ($minutes <= 0) {
        return '';
    }
    $d = intdiv($minutes, 1440);
    $h = intdiv($minutes % 1440, 60);
    $m = $minutes % 60;
    $parts = [];
    if ($d > 0) { $parts[] = $d . 'd'; }
    if ($h > 0) { $parts[] = $h . 'h'; }
    if ($m > 0) { $parts[] = $m . 'm'; }
    return implode('', $parts);
}

// Enabled routers for the selector.
$routerList = [];
try {
    $st = $pdo->query('SELECT id, name, host, api_type FROM routers ORDER BY name COLLATE NOCASE ASC');
    $routerList = $st ? $st->fetchAll() : [];
} catch (Throwable $e) {
    $routerList = [];
}

// Selected router id (POST wins over GET so actions stay on the same device).
$routerId = (int) ($_POST['router_id'] ?? $_GET['router'] ?? 0);
$router   = $routerId > 0 ? aircoins_get_router($pdo, $routerId) : null;

// ---------------------------------------------------------------------------
// POST actions
// ---------------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_verify();

    $action = (string) ($_POST['action'] ?? '');
    $back   = 'Location: hotspot.php' . ($routerId > 0 ? '?router=' . $routerId : '');

    if (!$router) {
        aircoins_flash('error', 'Select a router first.');
        header('Location: hotspot.php');
        exit;
    }

    // Open a client once for the mutating actions.
    $client = null;
    $clientErr = '';
    try {
        $client = aircoins_router_client($router);
    } catch (Throwable $e) {
        $clientErr = $e->getMessage();
    }

    if ($client === null) {
        aircoins_flash('error', 'Cannot reach “' . $router['name'] . '”: ' . $clientErr);
        header($back);
        exit;
    }

    // ---- add single user ---------------------------------------------------
    if ($action === 'add_user') {
        $name        = trim((string) ($_POST['name'] ?? ''));
        $pass        = (string) ($_POST['password'] ?? '');
        $profile     = trim((string) ($_POST['profile'] ?? ''));
        $comment     = trim((string) ($_POST['comment'] ?? ''));
        $uptimeMin   = max(0, (int) ($_POST['uptime_minutes'] ?? 0));
        $uptimeLimit = aircoins_minutes_to_time($uptimeMin);
        if ($name === '' || $pass === '') {
            aircoins_flash('error', 'Username and password are required.');
        } else {
            try {
                $client->addHotspotUser($name, $pass, $profile, $comment, $uptimeLimit);
                $msg = 'Hotspot user “' . $name . '” created';
                if ($uptimeLimit !== '') {
                    $msg .= ' (session limit ' . $uptimeLimit . ')';
                }
                aircoins_audit($pdo, $adminId, 'hotspot_user_add', 'router #' . $routerId . ' user ' . $name . ($uptimeLimit !== '' ? ' uptime=' . $uptimeLimit : ''));
                aircoins_flash('success', $msg . '.');
            } catch (Throwable $e) {
                aircoins_flash('error', 'Add user failed: ' . $e->getMessage());
            }
        }
        header($back . '#users');
        exit;
    }

    // ---- delete user -------------------------------------------------------
    if ($action === 'delete_user') {
        $id   = (string) ($_POST['id'] ?? '');
        $name = (string) ($_POST['name'] ?? '');
        if ($id === '') {
            aircoins_flash('error', 'Missing user id.');
        } else {
            try {
                $client->deleteHotspotUser($id);
                aircoins_audit($pdo, $adminId, 'hotspot_user_delete', 'router #' . $routerId . ' user ' . ($name !== '' ? $name : $id));
                aircoins_flash('success', 'User “' . ($name !== '' ? $name : $id) . '” deleted.');
            } catch (Throwable $e) {
                aircoins_flash('error', 'Delete failed: ' . $e->getMessage());
            }
        }
        header($back . '#users');
        exit;
    }

    // ---- bulk voucher generator -------------------------------------------
    if ($action === 'generate') {
        $prefix      = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) ($_POST['prefix'] ?? 'AIR')) ?: 'AIR');
        $count       = max(1, min(200, (int) ($_POST['count'] ?? 1)));
        $len         = max(4, min(12, (int) ($_POST['code_len'] ?? 6)));
        $profile     = trim((string) ($_POST['profile'] ?? ''));
        $comment     = trim((string) ($_POST['comment'] ?? '')) ?: ('voucher batch ' . date('Y-m-d H:i'));
        $uptimeMin   = max(0, (int) ($_POST['uptime_minutes'] ?? 0));
        $uptimeLimit = aircoins_minutes_to_time($uptimeMin);

        $created = [];
        $failed  = [];
        for ($i = 0; $i < $count; $i++) {
            $code = aircoins_voucher_code($prefix, $len);
            try {
                $client->addHotspotUser($code, $code, $profile, $comment, $uptimeLimit);
                $created[] = $code;
                // Track voucher in voucher_log (unused until first login).
                try {
                    $vIns = $pdo->prepare('INSERT INTO voucher_log (code, router_id, used_at, expires_at) VALUES (:code, :rid, NULL, :exp)');
                    $expTs = $uptimeMin > 0 ? time() + ($uptimeMin * 60) : null;
                    $vIns->execute([':code' => $code, ':rid' => $routerId, ':exp' => $expTs]);
                } catch (Throwable $ve) {
                    // Non-fatal: voucher was created on router even if logging fails.
                }
            } catch (Throwable $e) {
                $failed[] = $code . ' (' . $e->getMessage() . ')';
                // Abort early if the very first fails (likely profile/permission).
                if ($created === [] && $i === 0) {
                    break;
                }
            }
        }

        $_SESSION['aircoins_vouchers'] = [
            'created'      => $created,
            'failed'       => $failed,
            'profile'      => $profile,
            'router'       => (string) $router['name'],
            'uptime_limit' => $uptimeLimit,
        ];
        aircoins_audit($pdo, $adminId, 'hotspot_voucher_generate',
            'router #' . $routerId . ' generated ' . count($created) . '/' . $count . ' vouchers (profile ' . ($profile !== '' ? $profile : 'default') . ($uptimeLimit !== '' ? ', uptime=' . $uptimeLimit : '') . ')');
        if ($created === []) {
            aircoins_flash('error', 'No vouchers created' . ($failed !== [] ? ': ' . $failed[0] : '.'));
        } else {
            aircoins_flash('success', count($created) . ' voucher(s) created' . ($failed !== [] ? ' (' . count($failed) . ' failed)' : '') . '.');
        }
        header($back . '#users');
        exit;
    }

    // ---- kick session ------------------------------------------------------
    if ($action === 'kick') {
        $id   = (string) ($_POST['id'] ?? '');
        $user = (string) ($_POST['user'] ?? '');
        if ($id === '') {
            aircoins_flash('error', 'Missing session id.');
        } else {
            try {
                $client->kickSession($id);
                aircoins_audit($pdo, $adminId, 'hotspot_session_kick', 'router #' . $routerId . ' session ' . $id . ' user ' . $user);
                aircoins_flash('success', 'Disconnected ' . ($user !== '' ? '"' . $user . '"' : 'session') . '.');
            } catch (Throwable $e) {
                aircoins_flash('error', 'Kick failed: ' . $e->getMessage());
            }
        }
        header($back . '#sessions');
        exit;
    }
    
    // ---- create profile ----------------------------------------------------
    if ($action === 'add_profile') {
        $name = trim((string) ($_POST['profile_name'] ?? ''));
        if ($name === '') {
            aircoins_flash('error', 'Profile name is required.');
        } else {
            $attrs = ['name' => $name];
            $sessTimeout = trim((string) ($_POST['session_timeout'] ?? ''));
            if ($sessTimeout !== '') { $attrs['session-timeout'] = $sessTimeout; }
            $uptimeLimit = trim((string) ($_POST['uptime_limit'] ?? ''));
            if ($uptimeLimit !== '') { $attrs['uptime-limit'] = $uptimeLimit; }
            $rateLimit = trim((string) ($_POST['rate_limit'] ?? ''));
            if ($rateLimit !== '') { $attrs['rate-limit'] = $rateLimit; }
            $sharedUsers = trim((string) ($_POST['shared_users'] ?? ''));
            if ($sharedUsers !== '') { $attrs['shared-users'] = $sharedUsers; }
            $idleTimeout = trim((string) ($_POST['idle_timeout'] ?? ''));
            if ($idleTimeout !== '') { $attrs['idle-timeout'] = $idleTimeout; }
            try {
                $client->addHotspotProfile($attrs);
                aircoins_audit($pdo, $adminId, 'hotspot_profile_add', 'router #' . $routerId . ' profile ' . $name);
                aircoins_flash('success', 'Profile "' . $name . '" created.');
            } catch (Throwable $e) {
                aircoins_flash('error', 'Create profile failed: ' . $e->getMessage());
            }
        }
        header($back . '#profiles');
        exit;
    }
    
    // ---- delete profile ----------------------------------------------------
    if ($action === 'delete_profile') {
        $id   = (string) ($_POST['id'] ?? '');
        $name = (string) ($_POST['name'] ?? '');
        if ($id === '') {
            aircoins_flash('error', 'Missing profile id.');
        } else {
            try {
                $client->deleteHotspotProfile($id);
                aircoins_audit($pdo, $adminId, 'hotspot_profile_delete', 'router #' . $routerId . ' profile ' . ($name !== '' ? $name : $id));
                aircoins_flash('success', 'Profile "' . ($name !== '' ? $name : $id) . '" deleted.');
            } catch (Throwable $e) {
                aircoins_flash('error', 'Delete profile failed: ' . $e->getMessage());
            }
        }
        header($back . '#profiles');
        exit;
    }

    // Unknown action.
    aircoins_flash('error', 'Unknown action.');
    header($back);
    exit;
}

// ---------------------------------------------------------------------------
// Read live data for display
// ---------------------------------------------------------------------------
$users = [];
$sessions = [];
$profiles = [];
$banner = '';
$bannerType = 'error';
$client = null;

if ($router) {
    try {
        $client = aircoins_router_client($router);
        try { $users = $client->hotspotUsers(); } catch (Throwable $e) { $users = []; $banner = 'Could not load hotspot users: ' . $e->getMessage(); }
        try { $sessions = $client->activeSessions(); } catch (Throwable $e) { $sessions = []; if ($banner === '') { $banner = 'Could not load active sessions: ' . $e->getMessage(); } }
        try { $profiles = $client->hotspotProfiles(); } catch (Throwable $e) { $profiles = []; if ($banner === '') { $banner = 'Could not load hotspot profiles: ' . $e->getMessage(); } }
    } catch (Throwable $e) {
        $banner = 'Cannot reach router "' . (string) $router['name'] . '": ' . $e->getMessage();
    }
}

// Consume a voucher batch result (PRG).
$voucherResult = null;
if (!empty($_SESSION['aircoins_vouchers']) && is_array($_SESSION['aircoins_vouchers'])) {
    $voucherResult = $_SESSION['aircoins_vouchers'];
    unset($_SESSION['aircoins_vouchers']);
}

// Build voucher status lookup from voucher_log.
$voucherStatus = [];
try {
    $vRows = $pdo->query('SELECT code, mac, ip, used_at, expires_at FROM voucher_log ORDER BY id DESC')->fetchAll();
    foreach ($vRows as $vr) {
        $voucherStatus[(string) ($vr['code'] ?? '')] = $vr;
    }
} catch (Throwable $e) {
    // Non-fatal.
}

aircoins_header('Hotspot', 'hotspot');
?>

<div class="card" style="margin-bottom:20px">
  <div class="card__body">
    <form method="get" action="hotspot.php" class="row row--between" style="gap:14px">
      <div class="row" style="flex:1;min-width:240px">
        <label class="label" for="router-select" style="white-space:nowrap">Router</label>
        <select class="select" id="router-select" name="router" style="max-width:360px" onchange="this.form.submit()">
          <option value="">— select a router —</option>
          <?php foreach ($routerList as $rr): ?>
            <option value="<?php echo (int) $rr['id']; ?>" <?php echo $routerId === (int) $rr['id'] ? 'selected' : ''; ?>>
              <?php echo e((string) $rr['name'] . ' (' . $rr['host'] . ' · ' . strtoupper((string) $rr['api_type']) . ')'); ?>
            </option>
          <?php endforeach; ?>
        </select>
        <noscript><button class="btn btn--ghost btn--sm" type="submit">Load</button></noscript>
      </div>
      <?php if ($router): ?>
        <div class="row">
          <span class="badge <?php echo strtolower((string) $router['api_type']) === 'rest' ? 'badge--rest' : 'badge--legacy'; ?>"><?php echo e(strtoupper((string) $router['api_type'])); ?></span>
          <?php if ((int) $router['disabled'] === 1): ?><span class="badge badge--off">DISABLED</span><?php endif; ?>
          <span class="hint mono"><?php echo e((string) $router['host']); ?>:<?php echo (int) $router['api_port']; ?></span>
        </div>
      <?php endif; ?>
    </form>
  </div>
</div>

<?php if ($banner !== ''): ?>
  <div class="flash flash--<?php echo e($bannerType); ?>" style="margin-bottom:20px">
    <span class="flash__bar"></span><span class="flash__msg"><?php echo e($banner); ?></span>
    <button class="flash__x" type="button" data-flash-close aria-label="Dismiss">&times;</button>
  </div>
<?php endif; ?>

<?php if (!$router): ?>
  <div class="card"><div class="card__body">
    <div class="empty">
      <svg viewBox="0 0 24 24" width="40" height="40" aria-hidden="true"><path fill="currentColor" d="M12 3a9 9 0 0 0-9 9h2a7 7 0 1 1 14 0h2a9 9 0 0 0-9-9Zm0 13a2 2 0 1 0 0 4 2 2 0 0 0 0-4Z"/></svg>
      <div><?php echo $routerList === [] ? 'No routers configured yet' : 'Select a router to begin'; ?></div>
      <?php if ($routerList === []): ?>
        <a class="btn btn--primary" href="routers.php?add=1" style="margin-top:14px">Add router</a>
      <?php endif; ?>
    </div>
  </div></div>
<?php else: ?>

<div>
  <div class="tabs">
    <button class="tab is-active" type="button" data-tab="users">Users &amp; Vouchers</button>
    <button class="tab" type="button" data-tab="sessions">Active Sessions <span class="badge badge--idle" style="margin-left:6px"><?php echo count($sessions); ?></span></button>
    <button class="tab" type="button" data-tab="profiles">Profiles <span class="badge badge--idle" style="margin-left:6px"><?php echo count($profiles); ?></span></button>
  </div>

  <!-- ============================ USERS ============================ -->
  <section class="tabpanel" data-panel="users">

    <?php if ($voucherResult !== null && $voucherResult['created'] !== []): ?>
      <div class="card" style="margin-bottom:20px;border-color:#a9e2ea">
        <div class="card__head">
          <h2 class="card__title">Generated vouchers</h2>
          <div class="spacer"></div>
          <span class="hint"><?php echo count($voucherResult['created']); ?> codes · profile <?php echo e($voucherResult['profile'] !== '' ? $voucherResult['profile'] : 'default'); ?><?php echo (!empty($voucherResult['uptime_limit'])) ? ' · session ' . e($voucherResult['uptime_limit']) : ''; ?> · <?php echo e($voucherResult['router']); ?></span>
        </div>
        <div class="card__body">
          <div class="row" style="gap:8px;flex-wrap:wrap">
            <?php foreach ($voucherResult['created'] as $code): ?>
              <code class="badge badge--rest" style="font-size:13px;padding:6px 12px"><?php echo e((string) $code); ?></code>
            <?php endforeach; ?>
          </div>
          <?php if (!empty($voucherResult['failed'])): ?>
            <p class="hint" style="color:var(--bad);margin-top:12px">Failed: <?php echo e(implode(', ', $voucherResult['failed'])); ?></p>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>

    <div class="grid grid--2" style="margin-bottom:20px;align-items:start">
      <!-- add single user -->
      <div class="card">
        <div class="card__head"><h2 class="card__title">Add user</h2></div>
        <div class="card__body">
          <form method="post" action="hotspot.php" autocomplete="off">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="add_user">
            <input type="hidden" name="router_id" value="<?php echo $routerId; ?>">
            <div class="field">
              <label for="u-name">Username</label>
              <input class="input" id="u-name" name="name" type="text" required>
            </div>
            <div class="field">
              <label for="u-pass">Password</label>
              <input class="input" id="u-pass" name="password" type="text" required>
            </div>
            <div class="field">
              <label for="u-profile">Profile</label>
              <select class="select" id="u-profile" name="profile">
                <?php if ($profiles === []): ?><option value="default">default</option><?php endif; ?>
                <?php foreach ($profiles as $p): ?>
                  <option value="<?php echo e((string) $p['name']); ?>"><?php echo e((string) $p['name']); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="field">
              <label for="u-uptime">Session time</label>
              <select class="select" id="u-uptime" name="uptime_minutes">
                <option value="0">No limit (use profile)</option>
                <option value="10">10 minutes</option>
                <option value="15">15 minutes</option>
                <option value="30">30 minutes</option>
                <option value="60" selected>1 hour</option>
                <option value="120">2 hours</option>
                <option value="180">3 hours</option>
                <option value="300">5 hours</option>
                <option value="360">6 hours</option>
                <option value="720">12 hours</option>
                <option value="1440">24 hours (1 day)</option>
              </select>
            </div>
            <div class="field">
              <label for="u-comment">Comment</label>
              <input class="input" id="u-comment" name="comment" type="text" placeholder="optional">
            </div>
            <button class="btn btn--primary btn--block" type="submit">Create user</button>
          </form>
        </div>
      </div>

      <!-- bulk voucher generator -->
      <div class="card">
        <div class="card__head"><h2 class="card__title">Bulk voucher generator</h2></div>
        <div class="card__body">
          <form method="post" action="hotspot.php" data-voucher-form autocomplete="off">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="generate">
            <input type="hidden" name="router_id" value="<?php echo $routerId; ?>">
            <div class="form-grid">
              <div class="field">
                <label for="v-prefix">Prefix</label>
                <input class="input input--mono" id="v-prefix" name="prefix" type="text" value="AIR" maxlength="8">
              </div>
              <div class="field">
                <label for="v-count">Count</label>
                <input class="input input--mono" id="v-count" name="count" type="number" min="1" max="200" value="10">
              </div>
            </div>
            <div class="form-grid">
              <div class="field">
                <label for="v-len">Code length</label>
                <input class="input input--mono" id="v-len" name="code_len" type="number" min="4" max="12" value="6">
              </div>
              <div class="field">
                <label for="v-profile">Profile</label>
                <select class="select" id="v-profile" name="profile">
                  <?php if ($profiles === []): ?><option value="default">default</option><?php endif; ?>
                  <?php foreach ($profiles as $p): ?>
                    <option value="<?php echo e((string) $p['name']); ?>"><?php echo e((string) $p['name']); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
            <div class="field">
              <label for="v-uptime">Session time</label>
              <select class="select" id="v-uptime" name="uptime_minutes">
                <option value="0">No limit (use profile)</option>
                <option value="10">10 minutes</option>
                <option value="15">15 minutes</option>
                <option value="30">30 minutes</option>
                <option value="60" selected>1 hour</option>
                <option value="120">2 hours</option>
                <option value="180">3 hours</option>
                <option value="300">5 hours</option>
                <option value="360">6 hours</option>
                <option value="720">12 hours</option>
                <option value="1440">24 hours (1 day)</option>
              </select>
            </div>
            <div class="field">
              <label for="v-comment">Comment</label>
              <input class="input" id="v-comment" name="comment" type="text" placeholder="voucher batch">
            </div>
            <p class="hint">Format: <code data-voucher-preview>AIRXXXXXX</code> — each code is used as both username and password. Session time is set as the uptime-limit on the MikroTik user.</p>
            <button class="btn btn--primary btn--block" type="submit"
                    data-confirm="Generate and push these vouchers to the router now?">Generate vouchers</button>
          </form>
        </div>
      </div>
    </div>

    <!-- users table -->
    <div class="card">
      <div class="card__head">
        <h2 class="card__title">Hotspot users</h2>
        <div class="spacer"></div>
        <span class="hint"><?php echo count($users); ?> user(s)</span>
      </div>
      <div class="card__body card__body--flush">
        <?php if ($users === []): ?>
          <div class="empty">No hotspot users found</div>
        <?php else: ?>
          <div class="table-wrap">
            <table class="data">
              <thead><tr><th>.id</th><th>Name</th><th>Profile</th><th>Session Limit</th><th>Comment</th><th>Voucher</th><th>State</th><th class="actions">Actions</th></tr></thead>
              <tbody>
              <?php foreach ($users as $u): ?>
                <tr>
                  <td class="mono"><?php echo e((string) ($u['.id'] ?? '')); ?></td>
                  <td><strong><?php echo e((string) ($u['name'] ?? '')); ?></strong></td>
                  <td><?php echo e((string) ($u['profile'] ?? '')); ?></td>
                  <td class="mono"><?php echo e((string) ($u['limit-uptime'] ?? '')); ?></td>
                  <td class="hint"><?php echo e((string) ($u['comment'] ?? '')); ?></td>
                  <td>
                    <?php
                    $uName = (string) ($u['name'] ?? '');
                    $vInfo = $voucherStatus[$uName] ?? null;
                    if ($vInfo !== null && !empty($vInfo['used_at'])):
                    ?>
                      <span class="badge badge--idle" title="Used <?php echo e(date('M j H:i', (int) $vInfo['used_at'])); ?><?php echo $vInfo['mac'] ? ' · ' . e((string) $vInfo['mac']) : ''; ?>">USED</span>
                    <?php elseif ($vInfo !== null): ?>
                      <span class="badge badge--online">READY</span>
                    <?php else: ?>
                      <span class="hint">—</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <?php if (!empty($u['disabled'])): ?>
                      <span class="badge badge--off">DISABLED</span>
                    <?php else: ?>
                      <span class="badge badge--online">ACTIVE</span>
                    <?php endif; ?>
                  </td>
                  <td class="actions">
                    <form method="post" action="hotspot.php" style="display:inline">
                      <?php echo csrf_field(); ?>
                      <input type="hidden" name="action" value="delete_user">
                      <input type="hidden" name="router_id" value="<?php echo $routerId; ?>">
                      <input type="hidden" name="id" value="<?php echo e((string) ($u['.id'] ?? '')); ?>">
                      <input type="hidden" name="name" value="<?php echo e((string) ($u['name'] ?? '')); ?>">
                      <button class="btn btn--danger btn--sm" type="submit"
                              data-confirm="Delete hotspot user “<?php echo e((string) ($u['name'] ?? '')); ?>”?">Delete</button>
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
  </section>

  <!-- ========================= ACTIVE SESSIONS ========================= -->
  <section class="tabpanel" data-panel="sessions" hidden>
    <div class="card">
      <div class="card__head">
        <h2 class="card__title">Active sessions</h2>
        <div class="spacer"></div>
        <span class="hint"><?php echo count($sessions); ?> connected</span>
      </div>
      <div class="card__body card__body--flush">
        <?php if ($sessions === []): ?>
          <div class="empty">No active sessions</div>
        <?php else: ?>
          <div class="table-wrap">
            <table class="data">
              <thead><tr><th>User</th><th>MAC</th><th>Address</th><th>Uptime</th><th>In</th><th>Out</th><th class="actions">Actions</th></tr></thead>
              <tbody>
              <?php foreach ($sessions as $s): ?>
                <tr>
                  <td><strong><?php echo e((string) ($s['user'] ?? '')); ?></strong></td>
                  <td class="mono"><?php echo e((string) ($s['mac'] ?? '')); ?></td>
                  <td class="mono"><?php echo e((string) ($s['address'] ?? '')); ?></td>
                  <td class="mono"><?php echo e((string) ($s['uptime'] ?? '')); ?></td>
                  <td class="mono"><?php echo e(fmt_bytes((int) ($s['bytes-in'] ?? 0))); ?></td>
                  <td class="mono"><?php echo e(fmt_bytes((int) ($s['bytes-out'] ?? 0))); ?></td>
                  <td class="actions">
                    <form method="post" action="hotspot.php" style="display:inline">
                      <?php echo csrf_field(); ?>
                      <input type="hidden" name="action" value="kick">
                      <input type="hidden" name="router_id" value="<?php echo $routerId; ?>">
                      <input type="hidden" name="id" value="<?php echo e((string) ($s['.id'] ?? '')); ?>">
                      <input type="hidden" name="user" value="<?php echo e((string) ($s['user'] ?? '')); ?>">
                      <button class="btn btn--danger btn--sm" type="submit"
                              data-confirm="Disconnect <?php echo e((string) ($s['user'] ?? 'this session')); ?> now?">Kick</button>
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
  </section>

  <!-- ========================= PROFILES ========================= -->
  <section class="tabpanel" data-panel="profiles" hidden>

    <div class="grid grid--2" style="margin-bottom:20px;align-items:start">
      <!-- create profile form -->
      <div class="card">
        <div class="card__head"><h2 class="card__title">Create profile</h2></div>
        <div class="card__body">
          <form method="post" action="hotspot.php" autocomplete="off">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="add_profile">
            <input type="hidden" name="router_id" value="<?php echo $routerId; ?>">
            <div class="field">
              <label for="p-name">Profile name</label>
              <input class="input" id="p-name" name="profile_name" type="text" required placeholder="e.g. 1hr-voucher">
            </div>
            <div class="form-grid">
              <div class="field">
                <label for="p-session">Session timeout</label>
                <input class="input input--mono" id="p-session" name="session_timeout" type="text" placeholder="e.g. 1h or 30m">
              </div>
              <div class="field">
                <label for="p-uptime">Uptime limit</label>
                <input class="input input--mono" id="p-uptime" name="uptime_limit" type="text" placeholder="e.g. 1h or 1d">
              </div>
            </div>
            <div class="form-grid">
              <div class="field">
                <label for="p-rate">Rate limit (rx/tx)</label>
                <input class="input input--mono" id="p-rate" name="rate_limit" type="text" placeholder="e.g. 5M/5M">
              </div>
              <div class="field">
                <label for="p-shared">Shared users</label>
                <input class="input input--mono" id="p-shared" name="shared_users" type="number" min="1" max="100" value="1">
              </div>
            </div>
            <div class="field">
              <label for="p-idle">Idle timeout</label>
              <input class="input input--mono" id="p-idle" name="idle_timeout" type="text" placeholder="e.g. 5m (optional)">
            </div>
            <p class="hint">Time values use MikroTik format: <code>30s</code>, <code>5m</code>, <code>1h</code>, <code>1d</code>. Leave blank for no limit.</p>
            <button class="btn btn--primary btn--block" type="submit">Create profile</button>
          </form>
        </div>
      </div>

      <!-- profile hints card -->
      <div class="card">
        <div class="card__head"><h2 class="card__title">Profile guide</h2></div>
        <div class="card__body">
          <dl style="margin:0;font-size:13px">
            <dt style="font-weight:600;margin-top:8px">Session timeout</dt>
            <dd class="hint" style="margin:0 0 8px">Max idle time before disconnect. After this, the user must re-login.</dd>
            <dt style="font-weight:600;margin-top:8px">Uptime limit</dt>
            <dd class="hint" style="margin:0 0 8px">Total connected time allowed. The voucher's per-user uptime-limit overrides this.</dd>
            <dt style="font-weight:600;margin-top:8px">Rate limit</dt>
            <dd class="hint" style="margin:0 0 8px">Bandwidth cap per user. Format: <code>rx/tx</code> (e.g. <code>5M/5M</code> for 5 Mbps symmetric).</dd>
            <dt style="font-weight:600;margin-top:8px">Shared users</dt>
            <dd class="hint" style="margin:0 0 8px">How many devices can use one voucher simultaneously. Set to <strong>1</strong> for single-device vouchers.</dd>
          </dl>
        </div>
      </div>
    </div>

    <!-- profiles table -->
    <div class="card">
      <div class="card__head">
        <h2 class="card__title">Hotspot profiles</h2>
        <div class="spacer"></div>
        <span class="hint"><?php echo count($profiles); ?> profile(s)</span>
      </div>
      <div class="card__body card__body--flush">
        <?php if ($profiles === []): ?>
          <div class="empty">No hotspot profiles found</div>
        <?php else: ?>
          <div class="table-wrap">
            <table class="data">
              <thead><tr><th>.id</th><th>Name</th><th>Rate</th><th>Session</th><th>Uptime</th><th>Shared</th><th class="actions">Actions</th></tr></thead>
              <tbody>
              <?php foreach ($profiles as $p): ?>
                <tr>
                  <td class="mono"><?php echo e((string) ($p['.id'] ?? '')); ?></td>
                  <td><strong><?php echo e((string) ($p['name'] ?? '')); ?></strong></td>
                  <td class="mono"><?php echo e((string) ($p['rate-limit'] ?? '')); ?></td>
                  <td class="mono"><?php echo e((string) ($p['session-timeout'] ?? '')); ?></td>
                  <td class="mono"><?php echo e((string) ($p['uptime-limit'] ?? '')); ?></td>
                  <td class="mono"><?php echo e((string) ($p['shared-users'] ?? '')); ?></td>
                  <td class="actions">
                    <form method="post" action="hotspot.php" style="display:inline">
                      <?php echo csrf_field(); ?>
                      <input type="hidden" name="action" value="delete_profile">
                      <input type="hidden" name="router_id" value="<?php echo $routerId; ?>">
                      <input type="hidden" name="id" value="<?php echo e((string) ($p['.id'] ?? '')); ?>">
                      <input type="hidden" name="name" value="<?php echo e((string) ($p['name'] ?? '')); ?>">
                      <button class="btn btn--danger btn--sm" type="submit"
                              data-confirm="Delete hotspot profile \"<?php echo e((string) ($p['name'] ?? '')); ?>\"? Users assigned to this profile will lose their settings.">Delete</button>
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
  </section>
</div>

<?php endif; ?>

<?php aircoins_footer(); ?>
