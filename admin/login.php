<?php
/**
 * AIRCOINS NETFI — admin login.
 *
 * Standalone (does not use the app chrome). Rate-limited authentication via
 * aircoins_login_ok(); every attempt path is CSRF-protected and audited.
 *
 * GET  -> render the form (plus ?timeout=1 idle notice).
 * POST -> verify CSRF, authenticate, audit('login') and redirect to index.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';

aircoins_session_start();

// Already authenticated -> straight to the dashboard.
if (!empty($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$pdo = aircoins_db();
aircoins_schema($pdo); // idempotent: keeps a fresh install from fataling here.

$error   = '';
$notice  = '';
$username = '';

if (isset($_GET['timeout'])) {
    $notice = 'Your session expired after a period of inactivity. Please sign in again.';
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_verify();

    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        $error = 'Enter both your username and password.';
    } else {
        $admin = aircoins_login_ok($pdo, $username, $password);
        if (is_array($admin)) {
            $_SESSION['username'] = (string) ($admin['username'] ?? $username);
            aircoins_audit($pdo, (int) $admin['id'], 'login', 'admin login');
            header('Location: index.php');
            exit;
        }

        // Distinguish a lockout from bad credentials for a clearer message.
        if (aircoins_rate_limited($pdo, aircoins_client_ip())) {
            $error = 'Too many failed attempts. Locked out temporarily — wait '
                . (int) (AIRCOINS_RATE_WINDOW / 60) . ' minutes and try again.';
        } else {
            $error = 'Invalid username or password.';
        }
    }
}

$csrf = csrf_field();
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Sign in · AIRCOINS NETFI</title>
<link rel="stylesheet" href="assets/admin.css">
</head>
<body class="login">
  <div class="login-card">
    <div class="login-brand">
      <span class="rail__mark" aria-hidden="true">
        <svg viewBox="0 0 24 24" width="24" height="24"><path fill="currentColor" d="M12 2 2 20h20L12 2Zm0 5 6.2 11H5.8L12 7Zm0 4a2 2 0 1 0 0 4 2 2 0 0 0 0-4Z"/></svg>
      </span>
      <h1>AIRCOINS <span>NETFI</span></h1>
    </div>

    <?php if ($notice !== ''): ?>
      <div class="login-alert login-alert--info" role="status">
        <span aria-hidden="true">&#9432;</span><span><?php echo e($notice); ?></span>
      </div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
      <div class="login-alert login-alert--error" role="alert">
        <span aria-hidden="true">&#9888;</span><span><?php echo e($error); ?></span>
      </div>
    <?php endif; ?>

    <form method="post" action="login.php" autocomplete="on" novalidate>
      <?php echo $csrf; ?>
      <div class="field">
        <label for="username">Username</label>
        <input class="input" id="username" name="username" type="text" value="<?php echo e($username); ?>"
               autocomplete="username" required autofocus>
      </div>
      <div class="field">
        <label for="password">Password</label>
        <input class="input" id="password" name="password" type="password"
               autocomplete="current-password" required>
      </div>
      <button class="btn btn--primary btn--block" type="submit">Sign in</button>
    </form>

    <p class="login-foot">Authorized operators only &middot; all access is logged</p>
  </div>
</body>
</html>
