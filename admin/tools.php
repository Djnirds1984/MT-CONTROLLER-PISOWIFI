<?php
/**
 * AIRCOINS NETFI — Tools page.
 *
 * Operational utilities for the admin panel. Currently provides:
 *   Fix Router Hotspot Files — detects when the full SBC portal was uploaded
 *   to the router instead of the thin redirect stubs, and pushes the correct
 *   stub files via the RouterOS API.
 *
 * All POST actions are CSRF-verified and audited.
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

// ---------------------------------------------------------------------------
// Embedded router-stub templates (router-stubs/ is NOT deployed to the SBC,
// so we ship the content here). Each template uses {{SBC_IP}} as a placeholder
// that gets replaced with the operator's actual SBC address at upload time.
// ---------------------------------------------------------------------------

const AIRCOINS_STUB_LOGIN = <<<'HTML'
<!DOCTYPE html>
<!-- IAMNOTLOGINSTRINGPLEASEDONTREMOVE -->
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>AIRCOINS NETFI — Connecting…</title>
<meta http-equiv="refresh" content="0; url=http://{{SBC_IP}}/login.html?mac=$(mac-esc)&ip=$(ip-esc)&dst=$(link-orig-esc)&login=$(link-login-only-esc)&logout=$(link-logout-esc)&user=$(username-esc)&err=$(error-esc)">
<script>
  location.replace("http://{{SBC_IP}}/login.html?mac=$(mac-esc)&ip=$(ip-esc)&dst=$(link-orig-esc)&login=$(link-login-only-esc)&logout=$(link-logout-esc)&user=$(username-esc)&err=$(error-esc)");
</script>
</head>
<body>Redirecting to AIRCOINS NETFI portal...</body>
</html>
HTML;

const AIRCOINS_STUB_ALOGIN = <<<'HTML'
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>AIRCOINS NETFI — Connected</title>
<meta http-equiv="refresh" content="0; url=$(link-redirect)">
<script>
  (function () {
    var target = "$(link-redirect)";
    if (!target) { target = "http://{{SBC_IP}}/status.html"; }
    if (window.opener) { try { window.opener.location = target; } catch (e) {} window.close(); }
    location.replace(target);
  })();
</script>
</head>
<body>You are connected to AIRCOINS NETFI. Redirecting to your status page...</body>
</html>
HTML;

const AIRCOINS_STUB_ERROR = <<<'HTML'
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>AIRCOINS NETFI — Login error</title>
<meta http-equiv="refresh" content="0; url=http://{{SBC_IP}}/login.html?err=$(error-esc)&mac=$(mac-esc)&ip=$(ip-esc)&login=$(link-login-only-esc)">
<script>
  location.replace("http://{{SBC_IP}}/login.html?err=$(error-esc)&mac=$(mac-esc)&ip=$(ip-esc)&login=$(link-login-only-esc)");
</script>
</head>
<body>Login error. Redirecting back to the AIRCOINS NETFI portal...</body>
</html>
HTML;

const AIRCOINS_STUB_LOGOUT = <<<'HTML'
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>AIRCOINS NETFI — Disconnected</title>
<meta http-equiv="refresh" content="0; url=http://{{SBC_IP}}/login.html?mac=$(mac-esc)&ip=$(ip-esc)&login=$(link-login-only-esc)&logout=$(link-logout-esc)">
<script>
  location.replace("http://{{SBC_IP}}/login.html?mac=$(mac-esc)&ip=$(ip-esc)&login=$(link-login-only-esc)&logout=$(link-logout-esc)");
</script>
</head>
<body>You have been disconnected. Redirecting to the AIRCOINS NETFI portal...</body>
</html>
HTML;

/** Stub filename → template constant. */
const AIRCOINS_STUBS = [
    'login.html'  => AIRCOINS_STUB_LOGIN,
    'alogin.html' => AIRCOINS_STUB_ALOGIN,
    'error.html'  => AIRCOINS_STUB_ERROR,
    'logout.html' => AIRCOINS_STUB_LOGOUT,
];

// ---------------------------------------------------------------------------
// Session-selected router (from global topbar selector)
// ---------------------------------------------------------------------------
$router   = aircoins_selected_router($pdo);
$routerId = $router ? (int) $router['id'] : 0;

// ---------------------------------------------------------------------------
// POST handlers
// ---------------------------------------------------------------------------
$banner = '';
$diag   = null;   // diagnostic result
$fixed  = null;   // fix result

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action   = (string) ($_POST['action'] ?? '');

    if ($action === 'diagnose' && $router !== null) {
        try {
            $client = aircoins_router_client($router);
            // List files under flash/hotspot/ (or hotspot/) to check sizes.
            $files = $client->listFiles('flash/hotspot');
            $diag = [
                'router'   => (string) $router['name'],
                'files'    => $files,
                'stubs_ok' => true,
                'issues'   => [],
            ];
            // Check each stub file
            foreach (array_keys(AIRCOINS_STUBS) as $stub) {
                $found = null;
                foreach ($files as $f) {
                    if (basename((string) ($f['name'] ?? '')) === $stub) {
                        $found = $f;
                        break;
                    }
                }
                if ($found === null) {
                    $diag['issues'][] = "$stub is MISSING from the router.";
                    $diag['stubs_ok'] = false;
                } else {
                    $size = (int) ($found['size'] ?? 0);
                    // Thin stubs are < 3 KB; full portal login.html is ~23 KB.
                    if ($size > 3000) {
                        $diag['issues'][] = "$stub is {$size} bytes — likely the full portal, not the thin stub (should be < 3 KB).";
                        $diag['stubs_ok'] = false;
                    }
                }
            }
            if ($diag['stubs_ok'] && $diag['issues'] === []) {
                $diag['issues'][] = 'All stub files look correct (thin redirectors).';
            }
        } catch (Throwable $e) {
            aircoins_flash('error', 'Diagnose failed: ' . $e->getMessage());
        }
        // Do NOT redirect — render diag inline on this request.
    } elseif ($action === 'fix' && $router !== null) {
        $sbcIP = trim((string) ($_POST['sbc_ip'] ?? ''));
        if ($sbcIP === '') {
            aircoins_flash('error', 'SBC IP address is required.');
        } else {
            try {
                $client  = aircoins_router_client($router);
                $uploaded = [];
                $failed   = [];
                foreach (AIRCOINS_STUBS as $filename => $template) {
                    $content = str_replace('{{SBC_IP}}', $sbcIP, $template);
                    $path    = 'flash/hotspot/' . $filename;
                    try {
                        $client->uploadHotspotStub($path, $content);
                        $uploaded[] = $filename;
                    } catch (Throwable $e) {
                        $failed[] = $filename . ' (' . $e->getMessage() . ')';
                    }
                }
                if ($failed === []) {
                    aircoins_flash('success', count($uploaded) . ' stub file(s) uploaded to ' . $router['name'] . '. Voucher login should now work.');
                } else {
                    aircoins_flash('error', 'Uploaded ' . count($uploaded) . ' but ' . count($failed) . ' failed: ' . implode('; ', $failed));
                }
                aircoins_audit($pdo, $adminId, 'tools_fix_stubs',
                    'router #' . $routerId . ' (' . $router['name'] . ') SBC=' . $sbcIP . ' uploaded=' . implode(',', $uploaded));
                header('Location: tools.php');
                exit;
            } catch (Throwable $e) {
                aircoins_flash('error', 'Fix failed: ' . $e->getMessage());
                header('Location: tools.php');
                exit;
            }
        }
    }
    // diagnose: fall through to render results inline (no redirect)
}

// ---------------------------------------------------------------------------
// Page render
// ---------------------------------------------------------------------------
aircoins_header('Tools', 'tools');
?>

<div class="card">
  <div class="card__head">
    <h2 class="card__title">Fix Router Hotspot Files</h2>
  </div>
  <div class="card__body">
    <p class="hint" style="margin-bottom:16px">
      The router must hold <strong>thin redirect stubs</strong> (&lt;3 KB each) that immediately
      forward the browser to the SBC portal. If the stubs are <strong>missing</strong> or the
      <strong>full portal</strong> was uploaded instead, the captive portal will not redirect
      to the SBC &mdash; clients see the router&rsquo;s default page or a broken login screen.
      Use <strong>Diagnose</strong> to check, then <strong>Fix &mdash; Upload Stubs</strong> to push the correct files.
    </p>

    <!-- Router selector + SBC IP -->
    <form method="post" action="tools.php" id="toolsForm">
      <?php echo csrf_field(); ?>
      <input type="hidden" name="action" id="toolsAction" value="">

      <?php if (!$router): ?>
        <p class="hint" style="color:var(--bad)">Select a router from the top bar first.</p>
      <?php else: ?>
        <p class="hint" style="margin-bottom:12px">Router: <strong><?php echo e((string) $router['name']); ?></strong> (<?php echo e((string) $router['host']); ?>)</p>
      <?php endif; ?>
      <div class="field">
        <label for="t-sbcip">SBC Panel IP</label>
        <input class="input input--mono" id="t-sbcip" name="sbc_ip" type="text"
               placeholder="e.g. 10.0.0.252"
               pattern="^\d{1,3}(\.\d{1,3}){3}$"
               value="<?php echo e($_POST['sbc_ip'] ?? ''); ?>">
        <p class="hint">The IP of this panel — substituted into the stub redirect URLs.</p>
      </div>

      <div style="display:flex;gap:10px;margin-top:12px">
        <button class="btn btn--primary" type="submit" name="act" value="diagnose"
                onclick="document.getElementById('toolsAction').value='diagnose'" <?php echo !$router ? 'disabled' : ''; ?>>
          Diagnose
        </button>
        <button class="btn btn--danger" type="submit" name="act" value="fix"
                onclick="document.getElementById('toolsAction').value='fix'"
                data-confirm="This will overwrite login.html, alogin.html, error.html and logout.html on the router. Continue?" <?php echo !$router ? 'disabled' : ''; ?>>
          Fix — Upload Stubs
        </button>
      </div>
    </form>
  </div>
</div>

<?php if ($diag !== null): ?>
<div class="card" style="margin-top:16px">
  <div class="card__head">
    <h2 class="card__title">Diagnostic: <?php echo e($diag['router']); ?></h2>
  </div>
  <div class="card__body">
    <?php if ($diag['stubs_ok']): ?>
      <div class="flash flash--success" role="status" style="margin-bottom:12px">
        <span class="flash__bar" aria-hidden="true"></span>
        <span class="flash__msg">All 4 stub files are present and correctly sized.</span>
      </div>
    <?php else: ?>
      <div class="flash flash--error" role="status" style="margin-bottom:12px">
        <span class="flash__bar" aria-hidden="true"></span>
        <span class="flash__msg">Problems detected — click <strong>Fix — Upload Stubs</strong> above.</span>
      </div>
    <?php endif; ?>

    <table class="data">
      <thead><tr><th>File</th><th>Size</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach (array_keys(AIRCOINS_STUBS) as $stub):
        $found = null;
        foreach ($diag['files'] as $f) {
            if (basename((string) ($f['name'] ?? '')) === $stub) { $found = $f; break; }
        }
        $size = $found !== null ? (int) ($found['size'] ?? 0) : 0;
        $ok = $found !== null && $size <= 3000;
      ?>
        <tr>
          <td><code><?php echo e($stub); ?></code></td>
          <td class="mono"><?php echo $found !== null ? number_format($size) . ' B' : '—'; ?></td>
          <td>
            <?php if ($found === null): ?>
              <span style="color:#c62828;font-weight:600">MISSING</span>
            <?php elseif ($size > 3000): ?>
              <span style="color:#c62828;font-weight:600">TOO LARGE (full portal)</span>
            <?php else: ?>
              <span style="color:#2e7d32;font-weight:600">OK (thin stub)</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>

    <?php if ($diag['issues'] !== []): ?>
      <ul style="margin-top:12px;font-size:13px;color:var(--piso-muted,var(--text-muted,#555))">
        <?php foreach ($diag['issues'] as $issue): ?>
          <li><?php echo e($issue); ?></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<!-- Guide card -->
<div class="card" style="margin-top:16px">
  <div class="card__head"><h2 class="card__title">How it works</h2></div>
  <div class="card__body">
    <dl style="margin:0;font-size:13px">
      <dt style="font-weight:600;margin-top:8px">Why this fix is needed</dt>
      <dd class="hint" style="margin:0 0 8px">
        The router must serve <strong>thin redirect stubs</strong> (&lt;3 KB each) that immediately
        forward the browser to the SBC portal via meta-refresh. If the stubs are <strong>missing</strong>
        (deleted or never uploaded), RouterOS falls back to its built-in default hotspot pages and
        clients never reach the SBC portal. If the <strong>full portal</strong> (23 KB login.html)
        was uploaded instead, the router serves it directly &mdash; but the MikroTik
        <code>$(var)</code> tokens stay literal on the SBC, breaking the login flow.
      </dd>
      <dt style="font-weight:600;margin-top:8px">What the fix does</dt>
      <dd class="hint" style="margin:0 0 8px">
        Uploads 4 thin HTML files to <code>flash/hotspot/</code> on the router via the API.
        Each file is a meta-refresh + JS redirector carrying <code>$(mac-esc)</code>,
        <code>$(link-login-only-esc)</code> etc. to the SBC panel. The SBC IP in the redirect
        URLs is set from the form field above.
      </dd>
      <dt style="font-weight:600;margin-top:8px">Files uploaded</dt>
      <dd class="hint" style="margin:0">
        <code>flash/hotspot/login.html</code>,
        <code>flash/hotspot/alogin.html</code>,
        <code>flash/hotspot/error.html</code>,
        <code>flash/hotspot/logout.html</code>
      </dd>
    </dl>
  </div>
</div>

<?php aircoins_footer();
