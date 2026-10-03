<?php
/**
 * AIRCOINS NETFI — set the globally selected router.
 *
 * Accepts ?id=N, stores it in the session, and redirects back to the
 * referring page (or index.php when no referrer is available).
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

aircoins_require_login();

$pdo = aircoins_db();
$id  = (int) ($_GET['id'] ?? 0);

if ($id > 0) {
    $router = aircoins_get_router($pdo, $id);
    if ($router !== null && (int) ($router['disabled'] ?? 0) === 0) {
        aircoins_set_selected_router($id);
    }
} else {
    aircoins_set_selected_router(0);
}

// Optional explicit redirect target (e.g. dashboard Hotspot button).
$redirect = $_GET['redirect'] ?? '';
if (is_string($redirect) && preg_match('/^[a-zA-Z0-9_.\-]+\.php$/', $redirect)) {
    $back = $redirect;
} else {
    $back = $_SERVER['HTTP_REFERER'] ?? 'index.php';
}
header('Location: ' . $back);
exit;
