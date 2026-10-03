<?php
/**
 * AIRCOINS NETFI — router management.
 *
 * List + Add / Edit / Delete / Test-connection, plus a REST-vs-Legacy
 * auto-detect probe. Router passwords are encrypted with aircoins_encrypt()
 * before storage and are NEVER rendered back into a form (an edit leaves the
 * password blank to keep the stored secret).
 *
 * Every POST is CSRF-verified and audited. add/edit/delete/test use the
 * Post-Redirect-Get pattern with a one-shot flash; autodetect answers JSON
 * because admin.js calls it in-page.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/crypto.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/RouterOS/RouterFactory.php';

$admin = aircoins_require_login();
$adminId = (int) $admin['id'];

$pdo = aircoins_db();
aircoins_schema($pdo);

/** Update a router's last_status / last_error columns. */
function aircoins_set_status(PDO $pdo, int $id, ?string $status, ?string $error): void
{
    $stmt = $pdo->prepare('UPDATE routers SET last_status = :s, last_error = :e WHERE id = :id');
    $stmt->execute([':s' => $status, ':e' => $error, ':id' => $id]);
}

// ---------------------------------------------------------------------------
// POST handling
// ---------------------------------------------------------------------------
$formError = '';
$form      = [
    'id' => 0, 'name' => '', 'host' => '', 'api_type' => 'rest', 'api_port' => 443,
    'username' => '', 'tls_verify' => 0, 'disabled' => 0,
];
$modalOpen = false;
$mode      = 'add';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_verify();

    $action = (string) ($_POST['action'] ?? '');

    // ---- auto-detect (JSON) ------------------------------------------------
    if ($action === 'autodetect') {
        $host = trim((string) ($_POST['host'] ?? ''));
        $user = trim((string) ($_POST['username'] ?? ''));
        $pass = (string) ($_POST['password'] ?? '');
        $tls  = !empty($_POST['tls_verify']) && $_POST['tls_verify'] !== '0';
        $editId = (int) ($_POST['id'] ?? 0);

        // When editing, a blank password means "use the stored one".
        if ($pass === '' && $editId > 0) {
            $existing = aircoins_get_router($pdo, $editId);
            if ($existing) {
                try { $pass = aircoins_decrypt((string) $existing['pass_enc']); } catch (Throwable $e) { $pass = ''; }
                if ($host === '') { $host = (string) $existing['host']; }
                if ($user === '') { $user = (string) $existing['username']; }
            }
        }

        if ($host === '' || $user === '' || $pass === '') {
            aircoins_json(['ok' => false, 'message' => 'Host, username and password are required to probe.'], 200);
        }

        $probes = [
            ['api_type' => 'rest',   'api_port' => 443],
            ['api_type' => 'legacy', 'api_port' => 8728],
        ];
        foreach ($probes as $p) {
            try {
                $client = aircoins_router_client([
                    'host' => $host, 'api_port' => $p['api_port'], 'username' => $user,
                    'password' => $pass, 'tls_verify' => $tls, 'api_type' => $p['api_type'],
                ]);
                $res = $client->testConnection();
                if (!empty($res['ok'])) {
                    aircoins_json([
                        'ok' => true, 'api_type' => $p['api_type'], 'api_port' => $p['api_port'],
                        'message' => 'Detected ' . strtoupper($p['api_type']) . ' on port ' . $p['api_port']
                            . (isset($res['version']) && $res['version'] !== '' ? ' (RouterOS ' . $res['version'] . ')' : '') . '.',
                    ], 200);
                }
            } catch (Throwable $e) {
                continue;
            }
        }
        aircoins_json(['ok' => false, 'message' => 'No API answered on REST:443 or Legacy:8728. Check host, credentials, and that the service is enabled.'], 200);
    }

    // ---- delete ------------------------------------------------------------
    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        $row = aircoins_get_router($pdo, $id);
        if ($row) {
            $del = $pdo->prepare('DELETE FROM routers WHERE id = :id');
            $del->execute([':id' => $id]);
            $pdo->prepare('DELETE FROM monitor_samples WHERE router_id = :id')->execute([':id' => $id]);
            aircoins_audit($pdo, $adminId, 'router_delete', 'deleted router #' . $id . ' ' . $row['name']);
            aircoins_flash('success', 'Router “' . $row['name'] . '” deleted.');
        } else {
            aircoins_flash('error', 'Router not found.');
        }
        header('Location: routers.php');
        exit;
    }

    // ---- test connection ---------------------------------------------------
    if ($action === 'test') {
        $id  = (int) ($_POST['id'] ?? 0);
        $row = aircoins_get_router($pdo, $id);
        if (!$row) {
            aircoins_flash('error', 'Router not found.');
            header('Location: routers.php');
            exit;
        }
        try {
            $client = aircoins_router_client($row);
            $res = $client->testConnection();
            $ver = (string) ($res['version'] ?? '');
            $brd = (string) ($res['board-name'] ?? '');
            $status = 'OK' . ($ver !== '' ? ' · RouterOS ' . $ver : '') . ($brd !== '' ? ' · ' . $brd : '');
            aircoins_set_status($pdo, $id, $status, null);
            aircoins_audit($pdo, $adminId, 'router_test', 'test ok router #' . $id);
            aircoins_flash('success', '“' . $row['name'] . '” reachable — ' . $status . '.');
        } catch (Throwable $e) {
            aircoins_set_status($pdo, $id, 'FAILED', $e->getMessage());
            aircoins_audit($pdo, $adminId, 'router_test', 'test failed router #' . $id . ': ' . $e->getMessage());
            aircoins_flash('error', '“' . $row['name'] . '” test failed: ' . $e->getMessage());
        }
        header('Location: routers.php');
        exit;
    }

    // ---- add / edit --------------------------------------------------------
    if ($action === 'add' || $action === 'edit') {
        $mode = $action;
        $form['name']       = trim((string) ($_POST['name'] ?? ''));
        $form['host']       = trim((string) ($_POST['host'] ?? ''));
        $form['api_type']   = ((string) ($_POST['api_type'] ?? 'rest')) === 'legacy' ? 'legacy' : 'rest';
        $form['api_port']   = (int) ($_POST['api_port'] ?? ($form['api_type'] === 'rest' ? 443 : 8728));
        $form['username']   = trim((string) ($_POST['username'] ?? ''));
        $form['tls_verify'] = !empty($_POST['tls_verify']) ? 1 : 0;
        $form['disabled']   = !empty($_POST['disabled']) ? 1 : 0;
        $password           = (string) ($_POST['password'] ?? '');

        if ($action === 'edit') {
            $form['id'] = (int) ($_POST['id'] ?? 0);
            $existing = aircoins_get_router($pdo, $form['id']);
            if (!$existing) {
                aircoins_flash('error', 'Router not found.');
                header('Location: routers.php');
                exit;
            }
        }

        // Validate.
        if ($form['name'] === '')     { $formError = 'Name is required.'; }
        elseif ($form['host'] === '') { $formError = 'Host / IP is required.'; }
        elseif ($form['username'] === '') { $formError = 'API username is required.'; }
        elseif ($form['api_port'] < 1 || $form['api_port'] > 65535) { $formError = 'Port must be between 1 and 65535.'; }
        elseif ($action === 'add' && $password === '') { $formError = 'Password is required when adding a router.'; }

        if ($formError === '') {
            try {
                if ($action === 'add') {
                    $enc = aircoins_encrypt($password);
                    $ins = $pdo->prepare(
                        'INSERT INTO routers (name, host, api_type, api_port, username, pass_enc, tls_verify, disabled, last_status, last_error, created_at)
                         VALUES (:name, :host, :type, :port, :user, :enc, :tls, :dis, NULL, NULL, :ts)'
                    );
                    $ins->execute([
                        ':name' => $form['name'], ':host' => $form['host'], ':type' => $form['api_type'],
                        ':port' => $form['api_port'], ':user' => $form['username'], ':enc' => $enc,
                        ':tls' => $form['tls_verify'], ':dis' => $form['disabled'], ':ts' => time(),
                    ]);
                    $newId = (int) $pdo->lastInsertId();
                    aircoins_audit($pdo, $adminId, 'router_add', 'added router #' . $newId . ' ' . $form['name'] . ' (' . $form['api_type'] . ')');
                    aircoins_flash('success', 'Router “' . $form['name'] . '” added.');
                } else {
                    if ($password !== '') {
                        $enc = aircoins_encrypt($password);
                        $upd = $pdo->prepare(
                            'UPDATE routers SET name=:name, host=:host, api_type=:type, api_port=:port,
                             username=:user, pass_enc=:enc, tls_verify=:tls, disabled=:dis WHERE id=:id'
                        );
                        $upd->execute([
                            ':name' => $form['name'], ':host' => $form['host'], ':type' => $form['api_type'],
                            ':port' => $form['api_port'], ':user' => $form['username'], ':enc' => $enc,
                            ':tls' => $form['tls_verify'], ':dis' => $form['disabled'], ':id' => $form['id'],
                        ]);
                    } else {
                        $upd = $pdo->prepare(
                            'UPDATE routers SET name=:name, host=:host, api_type=:type, api_port=:port,
                             username=:user, tls_verify=:tls, disabled=:dis WHERE id=:id'
                        );
                        $upd->execute([
                            ':name' => $form['name'], ':host' => $form['host'], ':type' => $form['api_type'],
                            ':port' => $form['api_port'], ':user' => $form['username'],
                            ':tls' => $form['tls_verify'], ':dis' => $form['disabled'], ':id' => $form['id'],
                        ]);
                    }
                    aircoins_audit($pdo, $adminId, 'router_edit', 'edited router #' . $form['id'] . ' ' . $form['name']);
                    aircoins_flash('success', 'Router “' . $form['name'] . '” updated.');
                }
                header('Location: routers.php');
                exit;
            } catch (Throwable $e) {
                $formError = 'Could not save router: ' . $e->getMessage();
            }
        }

        // Fall through: re-open the modal with the submitted values + error.
        $modalOpen = true;
    }
}

// ---------------------------------------------------------------------------
// GET: edit / add intent
// ---------------------------------------------------------------------------
$editing = null;
if (!$modalOpen && isset($_GET['edit'])) {
    $editing = aircoins_get_router($pdo, (int) $_GET['edit']);
    if ($editing) {
        $mode = 'edit';
        $modalOpen = true;
        $form = [
            'id'         => (int) $editing['id'],
            'name'       => (string) $editing['name'],
            'host'       => (string) $editing['host'],
            'api_type'   => (string) $editing['api_type'],
            'api_port'   => (int) $editing['api_port'],
            'username'   => (string) $editing['username'],
            'tls_verify' => (int) $editing['tls_verify'],
            'disabled'   => (int) $editing['disabled'],
        ];
    }
}
if (!$modalOpen && isset($_GET['add'])) {
    $mode = 'add';
    $modalOpen = true;
    $form = ['id' => 0, 'name' => '', 'host' => '', 'api_type' => 'rest', 'api_port' => 443, 'username' => '', 'tls_verify' => 0, 'disabled' => 0];
}

// Load the list.
$rows = [];
try {
    $st = $pdo->query('SELECT * FROM routers ORDER BY name COLLATE NOCASE ASC');
    $rows = $st ? $st->fetchAll() : [];
} catch (Throwable $e) {
    $rows = [];
}

aircoins_header('Routers', 'routers');
?>

<div class="row row--between" style="margin-bottom:18px">
  <p class="lead" style="margin:0">
    MikroTik devices the controller talks to over the API. Choose <strong>REST</strong> (RouterOS v7)
    or <strong>Legacy</strong> binary API (v6 &amp; v7) per router — or let auto-detect probe both.
  </p>
  <a class="btn btn--primary" href="routers.php?add=1">
    <svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><path fill="currentColor" d="M11 5h2v6h6v2h-6v6h-2v-6H5v-2h6V5Z"/></svg>
    Add Router
  </a>
</div>

<div class="card">
  <div class="card__head">
    <h2 class="card__title">Configured routers</h2>
    <div class="spacer"></div>
    <span class="hint"><?php echo count($rows); ?> total</span>
  </div>
  <div class="card__body card__body--flush">
    <?php if ($rows === []): ?>
      <div class="empty">
        <svg viewBox="0 0 24 24" width="40" height="40" aria-hidden="true"><path fill="currentColor" d="M4 6h16v4H4V6Zm0 8h16v4H4v-4Z"/></svg>
        <div>No routers configured</div>
      </div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="data">
          <thead>
            <tr>
              <th>Name</th><th>Host</th><th>Type</th><th>Port</th><th>State</th><th>Last status</th><th class="actions">Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($rows as $r):
            $type = strtolower((string) $r['api_type']);
            $disabled = (int) $r['disabled'] === 1;
            $ls = (string) ($r['last_status'] ?? '');
            $le = (string) ($r['last_error'] ?? '');
          ?>
            <tr>
              <td><strong><?php echo e((string) $r['name']); ?></strong></td>
              <td class="mono"><?php echo e((string) $r['host']); ?></td>
              <td><span class="badge <?php echo $type === 'rest' ? 'badge--rest' : 'badge--legacy'; ?>"><?php echo e(strtoupper($type)); ?></span></td>
              <td class="mono"><?php echo (int) $r['api_port']; ?></td>
              <td>
                <?php if ($disabled): ?>
                  <span class="badge badge--off">DISABLED</span>
                <?php else: ?>
                  <span class="badge badge--online">ENABLED</span>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($ls !== ''): ?>
                  <span class="mono" style="font-size:12px"><?php echo e($ls); ?></span>
                <?php else: ?>
                  <span class="hint">never tested</span>
                <?php endif; ?>
                <?php if ($le !== ''): ?>
                  <div class="hint" style="color:var(--bad);font-size:11.5px"><?php echo e($le); ?></div>
                <?php endif; ?>
              </td>
              <td class="actions">
                <div class="btn-group" style="justify-content:flex-end">
                  <a class="btn btn--ghost btn--sm" href="hotspot.php?router=<?php echo (int) $r['id']; ?>">Hotspot</a>
                  <form method="post" action="routers.php" style="display:inline">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="test">
                    <input type="hidden" name="id" value="<?php echo (int) $r['id']; ?>">
                    <button class="btn btn--ghost btn--sm" type="submit">Test</button>
                  </form>
                  <a class="btn btn--ghost btn--sm" href="routers.php?edit=<?php echo (int) $r['id']; ?>">Edit</a>
                  <form method="post" action="routers.php" style="display:inline">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?php echo (int) $r['id']; ?>">
                    <button class="btn btn--danger btn--sm" type="submit"
                            data-confirm="Delete router “<?php echo e((string) $r['name']); ?>”? This cannot be undone.">Delete</button>
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

<!-- ------------------------------------------------------------ add/edit -- -->
<div class="modal modal--wide" id="router-modal" <?php echo $modalOpen ? '' : 'hidden'; ?>>
  <div class="modal__scrim" data-modal-close></div>
  <div class="modal__panel">
    <form method="post" action="routers.php" data-router-form data-mode="<?php echo e($mode); ?>" autocomplete="off">
      <?php echo csrf_field(); ?>
      <input type="hidden" name="action" value="<?php echo e($mode); ?>">
      <input type="hidden" name="id" value="<?php echo (int) $form['id']; ?>">

      <div class="modal__head">
        <h3><?php echo $mode === 'edit' ? 'Edit router' : 'Add router'; ?></h3>
        <button type="button" class="modal__x" data-modal-close aria-label="Close">&times;</button>
      </div>

      <div class="modal__body">
        <?php if ($formError !== ''): ?>
          <div class="flash flash--error" style="margin-bottom:18px"><span class="flash__bar"></span><span class="flash__msg"><?php echo e($formError); ?></span></div>
        <?php endif; ?>

        <div class="form-grid">
          <div class="field">
            <label for="r-name">Name</label>
            <input class="input" id="r-name" name="name" type="text" value="<?php echo e($form['name']); ?>" placeholder="Lobby Router" required>
          </div>
          <div class="field">
            <label for="r-host">Host / IP</label>
            <input class="input input--mono" id="r-host" name="host" type="text" value="<?php echo e($form['host']); ?>" placeholder="192.168.88.1" required>
          </div>
        </div>

        <div class="field">
          <span class="label">API type</span>
          <div class="radio-row">
            <label class="radio">
              <input type="radio" name="api_type" value="rest" <?php echo $form['api_type'] === 'rest' ? 'checked' : ''; ?>>
              <span class="radio__txt"><b>REST API</b><small>RouterOS v7 · port 443 (www-ssl) · JSON</small></span>
            </label>
            <label class="radio">
              <input type="radio" name="api_type" value="legacy" <?php echo $form['api_type'] === 'legacy' ? 'checked' : ''; ?>>
              <span class="radio__txt"><b>Legacy binary API</b><small>RouterOS v6 &amp; v7 · port 8728/8729 · binary</small></span>
            </label>
          </div>
        </div>

        <div class="form-grid">
          <div class="field">
            <label for="r-port">API port</label>
            <input class="input input--mono" id="r-port" name="api_port" type="number" min="1" max="65535" value="<?php echo (int) $form['api_port']; ?>">
            <span class="hint">Defaults: 443 (REST), 8728 (Legacy plain) / 8729 (Legacy TLS).</span>
          </div>
          <div class="field">
            <label for="r-user">API username</label>
            <input class="input" id="r-user" name="username" type="text" value="<?php echo e($form['username']); ?>" placeholder="admin" autocomplete="off">
          </div>
        </div>

        <div class="field">
          <label for="r-pass">API password</label>
          <input class="input" id="r-pass" name="password" type="password" value="" autocomplete="new-password"
                 placeholder="<?php echo $mode === 'edit' ? '•••••• (leave blank to keep)' : 'router API password'; ?>">
          <span class="hint" data-pw-hint><?php echo $mode === 'edit' ? 'Leave blank to keep the stored password.' : 'Router API password (encrypted at rest).'; ?></span>
        </div>

        <div class="row" style="gap:12px;margin-bottom:18px">
          <label class="check">
            <input type="checkbox" name="tls_verify" value="1" <?php echo (int) $form['tls_verify'] === 1 ? 'checked' : ''; ?>>
            <span class="check__txt"><b>Verify TLS certificate</b><small>Off is normal for self-signed routers</small></span>
          </label>
          <label class="check">
            <input type="checkbox" name="disabled" value="1" <?php echo (int) $form['disabled'] === 1 ? 'checked' : ''; ?>>
            <span class="check__txt"><b>Disabled</b><small>Exclude from monitoring &amp; lookups</small></span>
          </label>
        </div>

        <div class="note" style="margin-bottom:6px">
          <h4>Which is which?</h4>
          <table>
            <tr><th></th><th>REST API</th><th>Legacy API</th></tr>
            <tr><td>RouterOS</td><td>v7 only</td><td>v6 &amp; v7</td></tr>
            <tr><td>Service</td><td>www-ssl</td><td>api / api-ssl</td></tr>
            <tr><td>Port</td><td>443</td><td>8728 / 8729</td></tr>
            <tr><td>Protocol</td><td>HTTPS + Basic, JSON</td><td>binary sentences</td></tr>
          </table>
          <div class="row" style="margin-top:12px">
            <button type="button" class="btn btn--ghost btn--sm" data-autodetect>
              <svg viewBox="0 0 24 24" width="15" height="15" aria-hidden="true"><path fill="currentColor" d="M12 2a10 10 0 1 0 10 10A10 10 0 0 0 12 2Zm1 15h-2v-2h2Zm0-4h-2V7h2Z"/></svg>
              Auto-detect API type
            </button>
            <span class="hint">Probes REST:443 then Legacy:8728 using host + credentials above.</span>
          </div>
        </div>
      </div>

      <div class="modal__foot">
        <button type="button" class="btn btn--ghost" data-modal-close>Cancel</button>
        <button type="submit" class="btn btn--primary"><?php echo $mode === 'edit' ? 'Save changes' : 'Add router'; ?></button>
      </div>
    </form>
  </div>
</div>

<?php aircoins_footer(); ?>
