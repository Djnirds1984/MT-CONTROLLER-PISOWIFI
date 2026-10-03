<?php
/**
 * AIRCOINS NETFI — admin logout.
 *
 * Sign-out is a state-changing action, so it is restricted to POST and guarded
 * by CSRF verification. A non-POST request (e.g. a plain link or a forged
 * cross-site <img> hit) is bounced back to the dashboard WITHOUT touching the
 * session, defeating logout-CSRF. On a valid POST the sign-out is audited (if a
 * session was active), the session and its cookie are destroyed, and the
 * operator is returned to the login screen.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';

aircoins_session_start();

// Only an authenticated POST (carrying a valid CSRF token) may sign out.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Location: index.php');
    exit;
}

csrf_verify();

$adminId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;

if ($adminId !== null) {
    try {
        aircoins_audit(aircoins_db(), $adminId, 'logout', 'admin logout');
    } catch (Throwable $e) {
        // Never block logout on an audit write failure.
    }
}

aircoins_session_destroy();

header('Location: login.php');
exit;
