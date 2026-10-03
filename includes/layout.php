<?php
/**
 * AIRCOINS NETFI — shared admin panel chrome.
 *
 * Provides aircoins_header()/aircoins_footer() used by every authenticated
 * admin page. The layout renders a dark navigation rail, a top bar carrying the
 * logged-in username, and a single-shot flash-message slot. All CSS/JS is
 * self-contained (admin/assets/*) — no framework, no CDN.
 *
 * This file only emits markup; it never touches router APIs or the schema
 * beyond reading the flash slot out of the active session.
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/db.php';

/**
 * Queue a one-shot flash message for the next rendered page.
 *
 * Call before a redirect (PRG pattern). The message survives in the session and
 * is consumed by the next aircoins_header() call.
 *
 * @param string $type    One of: success, error, info, warn.
 * @param string $message Human-readable text (escaped on output).
 */
function aircoins_flash(string $type, string $message): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        aircoins_session_start();
    }
    $_SESSION['aircoins_flash'] = ['type' => $type, 'message' => $message];
}

/**
 * Consume and return any queued flash message.
 *
 * @return array{type:string,message:string}|null
 */
function aircoins_take_flash(): ?array
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return null;
    }
    if (empty($_SESSION['aircoins_flash']) || !is_array($_SESSION['aircoins_flash'])) {
        return null;
    }
    $flash = $_SESSION['aircoins_flash'];
    unset($_SESSION['aircoins_flash']);
    $type = (string) ($flash['type'] ?? 'info');
    if (!in_array($type, ['success', 'error', 'info', 'warn'], true)) {
        $type = 'info';
    }
    return ['type' => $type, 'message' => (string) ($flash['message'] ?? '')];
}

/**
 * Best-effort display name for the logged-in admin.
 *
 * @return string Username or a neutral fallback.
 */
function aircoins_current_username(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return 'admin';
    }
    $u = $_SESSION['username'] ?? '';
    return is_string($u) && $u !== '' ? $u : 'admin';
}

/**
 * Render the page header, navigation rail, top bar and flash slot.
 *
 * Emits everything up to (and including) the opening <main> content region so a
 * page can print its own body markup, then call aircoins_footer().
 *
 * @param string $title  Page title (also used in <title>).
 * @param string $active Nav key to highlight: dashboard|routers|hotspot.
 */
function aircoins_header(string $title, string $active = ''): void
{
    $brand   = 'AIRCOINS <span>NETFI</span>';
    $user    = aircoins_current_username();
    $initial = strtoupper(substr($user, 0, 1));

    $nav = [
        'dashboard' => ['index.php', 'Dashboard', 'M3 13h8V3H3v10Zm0 8h8v-6H3v6Zm10 0h8V11h-8v10Zm0-18v6h8V3h-8Z'],
        'routers'   => ['routers.php', 'Routers', 'M4 6h16v4H4V6Zm0 8h16v4H4v-4Zm2-6h2v2H6V8Zm0 8h2v2H6v-2Z'],
        'hotspot'   => ['hotspot.php', 'Hotspot', 'M12 3a9 9 0 0 0-9 9h2a7 7 0 1 1 14 0h2a9 9 0 0 0-9-9Zm0 5a4 4 0 0 0-4 4h2a2 2 0 1 1 4 0h2a4 4 0 0 0-4-4Zm0 8a2 2 0 1 0 0 4 2 2 0 0 0 0-4Z'],
        'devices'   => ['devices.php', 'Devices', 'M20 18c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2H4c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2H0v2h24v-2h-4ZM4 6h16v10H4V6Z'],
        'vendo'     => ['vendo.php', 'Vendo Setup', 'M1 9l2 2c4.97-4.97 13.03-4.97 18 0l2-2C16.93 2.93 7.08 2.93 1 9zm8 8l3 3 3-3c-1.65-1.66-4.34-1.66-6 0zm-4-4l2 2c2.76-2.76 7.24-2.76 10 0l2-2C15.14 9.14 8.87 9.14 5 13z'],
        'tools'     => ['tools.php', 'Tools', 'M22.7 19l-9.1-9.1c.9-2.3.4-5-1.5-6.9-2-2-5-2.4-7.4-1.3L9 6 6 9 1.6 4.7C.4 7.1.9 10.1 2.9 12.1c1.9 1.9 4.6 2.4 6.9 1.5l9.1 9.1c.4.4 1 .4 1.4 0l2.3-2.3c.5-.4.5-1.1.1-1.4Z'],
    ];
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo e($title); ?> · AIRCOINS NETFI</title>
<link rel="stylesheet" href="assets/admin.css">
</head>
<body data-page="<?php echo e($active); ?>">
<div class="shell">
  <aside class="rail">
    <a class="rail__brand" href="index.php">
      <span class="rail__mark" aria-hidden="true">
        <svg viewBox="0 0 24 24" width="22" height="22"><path fill="currentColor" d="M12 2 2 20h20L12 2Zm0 5 6.2 11H5.8L12 7Zm0 4a2 2 0 1 0 0 4 2 2 0 0 0 0-4Z"/></svg>
      </span>
      <span class="rail__wordmark"><?php echo $brand; ?></span>
    </a>
    <nav class="rail__nav" aria-label="Primary">
      <?php foreach ($nav as $key => $item): ?>
        <a class="rail__link<?php echo $key === $active ? ' is-active' : ''; ?>" href="<?php echo e($item[0]); ?>">
          <svg class="rail__icon" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path fill="currentColor" d="<?php echo e($item[2]); ?>"/></svg>
          <span><?php echo e($item[1]); ?></span>
        </a>
      <?php endforeach; ?>
    </nav>
    <div class="rail__foot">
      <form class="nav-logout-form" method="post" action="logout.php">
        <?php echo csrf_field(); ?>
        <button class="rail__link rail__link--danger nav-logout-btn" type="submit">
          <svg class="rail__icon" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path fill="currentColor" d="M10 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h5v-2H5V5h5V3Zm6.5 4.5-1.4 1.4L17.7 11H9v2h8.7l-2.6 2.6 1.4 1.4 5-5-5-5Z"/></svg>
          <span>Logout</span>
        </button>
      </form>
    </div>
  </aside>

  <div class="main">
    <header class="topbar">
      <button class="topbar__burger" type="button" data-nav-toggle aria-label="Toggle navigation" aria-expanded="false">
        <span></span><span></span><span></span>
      </button>
      <h1 class="topbar__title"><?php echo e($title); ?></h1>
      <div class="topbar__spacer"></div>
      <?php
      // Global router selector — available on every admin page.
      $_rl = [];
      try { $_rl = aircoins_db()->query('SELECT id, name, host, api_type FROM routers WHERE disabled = 0 ORDER BY name COLLATE NOCASE ASC')->fetchAll(); } catch (Throwable $_e) {}
      $_selId = aircoins_selected_router_id();
      ?>
      <form method="get" action="set_router.php" class="topbar__router-sel" id="routerSwitchForm">
        <label class="topbar__router-label" for="topRouter">Router</label>
        <select class="select" id="topRouter" name="id" onchange="this.form.submit()" style="min-width:180px">
          <option value="0">— select router —</option>
          <?php foreach ($_rl as $_r): ?>
            <option value="<?php echo (int) $_r['id']; ?>" <?php echo $_selId === (int) $_r['id'] ? 'selected' : ''; ?>>
              <?php echo e((string) $_r['name'] . ' (' . $_r['host'] . ')'); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </form>
      <div class="topbar__user">
        <span class="avatar" aria-hidden="true"><?php echo e($initial); ?></span>
        <span class="topbar__name"><?php echo e($user); ?></span>
      </div>
    </header>

    <main class="content">
    <?php
    $flash = aircoins_take_flash();
    if ($flash !== null): ?>
      <div class="flash flash--<?php echo e($flash['type']); ?>" role="status">
        <span class="flash__bar" aria-hidden="true"></span>
        <span class="flash__msg"><?php echo e($flash['message']); ?></span>
        <button class="flash__x" type="button" data-flash-close aria-label="Dismiss">&times;</button>
      </div>
    <?php endif;
}

/**
 * Close the content region and emit the shared script tag + document footer.
 */
function aircoins_footer(): void
{
    ?>
    </main>
    <footer class="pagefoot">
      <span>AIRCOINS NETFI Controller</span>
      <span class="pagefoot__dot" aria-hidden="true">•</span>
      <span id="clock"><?php echo e(date('Y-m-d H:i')); ?></span>
    </footer>
  </div>
</div>
<div class="toasts" id="toasts" aria-live="polite" aria-atomic="false"></div>
<div class="scrim" data-nav-scrim hidden></div>
<script src="assets/admin.js"></script>
</body>
</html>
    <?php
}
