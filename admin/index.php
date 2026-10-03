<?php
/**
 * AIRCOINS NETFI — dashboard.
 *
 * Renders one live card per ENABLED router. Cards ship with placeholder metric
 * spans tagged data-router-id; admin/assets/admin.js polls api/monitor.php
 * every 10s and fills identity/version/cpu/memory/uptime/active-count and the
 * per-interface traffic rates. A monitor failure flips the card to its error
 * state without breaking the page.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/layout.php';

$admin = aircoins_require_login();

$pdo = aircoins_db();
aircoins_schema($pdo);

$routers = [];
try {
    $stmt = $pdo->query('SELECT * FROM routers WHERE disabled = 0 ORDER BY name COLLATE NOCASE ASC');
    $routers = $stmt ? $stmt->fetchAll() : [];
} catch (Throwable $e) {
    $routers = [];
}

$totalRouters = 0;
try {
    $c = $pdo->query('SELECT COUNT(*) FROM routers');
    $totalRouters = $c ? (int) $c->fetchColumn() : 0;
} catch (Throwable $e) {
    $totalRouters = count($routers);
}

aircoins_header('Dashboard', 'dashboard');
?>

<div class="row row--between" style="margin-bottom:18px">
  <div>
    <p class="lead" style="margin:0">
      Live view of every enabled router. Metrics refresh automatically every 10 seconds
      via the monitor feed.
    </p>
  </div>
  <div class="row">
    <span class="hint" id="monitor-stamp">connecting…</span>
    <button class="btn btn--ghost btn--sm" type="button" id="monitor-refresh">
      <svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><path fill="currentColor" d="M12 5V2L7 6l5 4V7a5 5 0 1 1-5 5H5a7 7 0 1 0 7-7Z"/></svg>
      Refresh
    </button>
    <a class="btn btn--primary btn--sm" href="routers.php">
      <svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><path fill="currentColor" d="M11 5h2v6h6v2h-6v6h-2v-6H5v-2h6V5Z"/></svg>
      Manage routers
    </a>
  </div>
</div>

<?php if ($routers === []): ?>
  <div class="card">
    <div class="card__body">
      <div class="empty">
        <svg viewBox="0 0 24 24" width="40" height="40" aria-hidden="true"><path fill="currentColor" d="M4 6h16v4H4V6Zm0 8h16v4H4v-4Z"/></svg>
        <div>No enabled routers yet</div>
        <p class="hint" style="text-transform:none;font-family:var(--font-body);letter-spacing:0;margin-top:8px">
          <?php if ($totalRouters > 0): ?>
            All <?php echo (int) $totalRouters; ?> router(s) are disabled. Enable one from the Routers page.
          <?php else: ?>
            Add your first MikroTik router to start monitoring.
          <?php endif; ?>
        </p>
        <a class="btn btn--primary" href="routers.php" style="margin-top:14px">Add router</a>
      </div>
    </div>
  </div>
<?php else: ?>
  <div class="grid grid--cards">
    <?php foreach ($routers as $r):
      $rid   = (int) $r['id'];
      $type  = strtolower((string) $r['api_type']);
      $badge = $type === 'rest' ? 'badge--rest' : 'badge--legacy';
      $lastErr = (string) ($r['last_error'] ?? '');
      $lastStatus = (string) ($r['last_status'] ?? '');
    ?>
      <article class="card rcard" data-router-id="<?php echo $rid; ?>">
        <div class="rcard__top">
          <div class="rcard__id">
            <div class="rcard__name"><?php echo e((string) $r['name']); ?></div>
            <div class="rcard__host"><?php echo e((string) $r['host']); ?>:<?php echo (int) $r['api_port']; ?></div>
          </div>
          <div class="stack" style="gap:6px;align-items:flex-end">
            <span class="badge <?php echo $badge; ?>"><?php echo e(strtoupper($type)); ?></span>
            <span class="badge badge--idle" data-online><span></span>WAITING</span>
          </div>
        </div>

        <div class="rcard__metrics">
          <div class="metric">
            <div class="metric__k">Identity</div>
            <div class="metric__v" data-m-identity style="font-size:15px">—</div>
          </div>
          <div class="metric">
            <div class="metric__k">RouterOS</div>
            <div class="metric__v" data-m-version style="font-size:15px">—</div>
          </div>
          <div class="metric metric--wide">
            <div class="metric__k">CPU load</div>
            <div class="metric__v" data-m-cpu>—</div>
            <div class="meter" data-meter-cpu><i></i></div>
          </div>
          <div class="metric metric--wide">
            <div class="metric__k">Memory used</div>
            <div class="metric__v" data-m-memory style="font-size:15px">—</div>
            <div class="meter" data-meter-mem><i></i></div>
          </div>
          <div class="metric">
            <div class="metric__k">Uptime</div>
            <div class="metric__v" data-m-uptime style="font-size:15px">—</div>
          </div>
          <div class="metric">
            <div class="metric__k">Active sessions</div>
            <div class="metric__v" data-m-active>—</div>
          </div>
          <div class="iface-list">
            <div class="metric__k" style="margin-bottom:6px">Interface traffic</div>
            <div data-ifaces></div>
          </div>
        </div>

        <div class="rcard__err" data-error hidden>
          <?php echo $lastErr !== '' ? e($lastErr) : 'Waiting for first sample…'; ?>
        </div>

        <div class="rcard__top" style="border-top:1px solid var(--line-soft);border-bottom:0;padding:12px 20px">
          <span class="hint">
            Last check:
            <?php if ($lastStatus !== ''): ?>
              <strong><?php echo e($lastStatus); ?></strong>
            <?php else: ?>
              never
            <?php endif; ?>
          </span>
          <div class="topbar__spacer" style="flex:1"></div>
          <a class="btn btn--ghost btn--sm" href="hotspot.php?router=<?php echo $rid; ?>">Hotspot</a>
        </div>
      </article>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php aircoins_footer(); ?>
