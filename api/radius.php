<?php
/**
 * AIRCOINS NETFI — RADIUS user management API (JSON, NO admin auth).
 *
 * Provides CRUD operations for RADIUS users stored in the FreeRADIUS SQLite
 * database. Used by the admin panel and the NodeMCU firmware (via insertCoin).
 *
 * Contract:
 *   GET  ?action=list                  -> {users: [...]}
 *   GET  ?action=find&username=X       -> {user: {...}} or {error}
 *   POST ?action=add                   -> {id, username}
 *   POST ?action=remove&username=X     -> {removed: true}
 *   POST ?action=extend&username=X&add_seconds=N -> {new_total, username}
 *   POST ?action=set_session&username=X&seconds=N -> {session_timeout, username}
 *
 * CORS is opened for this endpoint (same reasoning as session.php).
 * Stack traces are never leaked.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/radius_db.php';

// --- response headers (permissive CORS, no caching) -------------------------
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('Access-Control-Allow-Origin: *');
header('X-Content-Type-Options: nosniff');

// --- route by action --------------------------------------------------------
$action = isset($_REQUEST['action']) ? (string) $_REQUEST['action'] : '';

try {
    $pdo = aircoins_radius_db();
    aircoins_radius_schema($pdo);

    switch ($action) {
        case 'list':
            handleList($pdo);
            break;

        case 'find':
            handleFind($pdo);
            break;

        case 'add':
            handleAdd($pdo);
            break;

        case 'remove':
            handleRemove($pdo);
            break;

        case 'extend':
            handleExtend($pdo);
            break;

        case 'set_session':
            handleSetSession($pdo);
            break;

        default:
            aircoins_json(['error' => 'unknown_action', 'valid' => [
                'list', 'find', 'add', 'remove', 'extend', 'set_session'
            ]], 400);
    }
} catch (Throwable $e) {
    aircoins_json(['error' => 'server_error', 'detail' => $e->getMessage()], 500);
}

// --- action handlers --------------------------------------------------------

function handleList(PDO $pdo): void
{
    $users = aircoins_radius_list_users($pdo);
    aircoins_json(['users' => $users], 200);
}

function handleFind(PDO $pdo): void
{
    $username = isset($_REQUEST['username']) ? trim((string) $_REQUEST['username']) : '';
    if ($username === '') {
        aircoins_json(['error' => 'username_required'], 400);
    }

    $user = aircoins_radius_find_user($pdo, $username);
    if ($user === null) {
        aircoins_json(['error' => 'user_not_found'], 404);
    }

    aircoins_json(['user' => $user], 200);
}

function handleAdd(PDO $pdo): void
{
    $username       = isset($_POST['username']) ? trim((string) $_POST['username']) : '';
    $password       = isset($_POST['password']) ? (string) $_POST['password'] : '';
    $sessionSeconds = isset($_POST['session_seconds']) ? (int) $_POST['session_seconds'] : 0;
    $comment        = isset($_POST['comment']) ? (string) $_POST['comment'] : '';

    if ($username === '') {
        aircoins_json(['error' => 'username_required'], 400);
    }

    $id = aircoins_radius_add_user($pdo, $username, $password, $sessionSeconds, $comment);
    aircoins_json(['id' => $id, 'username' => $username], 200);
}

function handleRemove(PDO $pdo): void
{
    $username = isset($_REQUEST['username']) ? trim((string) $_REQUEST['username']) : '';
    if ($username === '') {
        aircoins_json(['error' => 'username_required'], 400);
    }

    aircoins_radius_remove_user($pdo, $username);
    aircoins_json(['removed' => true, 'username' => $username], 200);
}

function handleExtend(PDO $pdo): void
{
    $username   = isset($_REQUEST['username']) ? trim((string) $_REQUEST['username']) : '';
    $addSeconds = isset($_REQUEST['add_seconds']) ? (int) $_REQUEST['add_seconds'] : 0;

    if ($username === '') {
        aircoins_json(['error' => 'username_required'], 400);
    }
    if ($addSeconds <= 0) {
        aircoins_json(['error' => 'add_seconds_must_be_positive'], 400);
    }

    $newTotal = aircoins_radius_extend_session($pdo, $username, $addSeconds);
    aircoins_json([
        'username'        => $username,
        'new_total'       => $newTotal,
        'session_timeout' => (string) $newTotal,
    ], 200);
}

function handleSetSession(PDO $pdo): void
{
    $username = isset($_REQUEST['username']) ? trim((string) $_REQUEST['username']) : '';
    $seconds  = isset($_REQUEST['seconds']) ? (int) $_REQUEST['seconds'] : 0;

    if ($username === '') {
        aircoins_json(['error' => 'username_required'], 400);
    }
    if ($seconds < 0) {
        aircoins_json(['error' => 'seconds_must_be_non_negative'], 400);
    }

    aircoins_radius_update_session($pdo, $username, $seconds);
    aircoins_json([
        'username'        => $username,
        'session_timeout' => (string) $seconds,
    ], 200);
}
