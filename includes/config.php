<?php
/**
 * AIRCOINS NETFI — central configuration.
 *
 * All constants are declared with a define-if-not-defined guard so that the
 * test harness (or an operator-supplied bootstrap file) can override any value
 * before this file is first included.
 *
 * No framework, no Composer: this file is plain PHP 8 and is safe to require
 * from any entry point.
 */

declare(strict_types=1);

/** Absolute path to the SQLite database file. */
if (!defined('AIRCOINS_DB')) {
    define('AIRCOINS_DB', '/var/lib/aircoins/aircoins.db');
}

/** Absolute path to the FreeRADIUS SQLite database file. */
if (!defined('AIRCOINS_RADIUS_DB')) {
    define('AIRCOINS_RADIUS_DB', '/var/lib/aircoins/radius.db');
}

/** Absolute path to the 32-byte libsodium secretbox key file. */
if (!defined('AIRCOINS_KEY')) {
    define('AIRCOINS_KEY', '/etc/aircoins/secret.key');
}

/** Name of the admin session cookie. */
if (!defined('AIRCOINS_SESSION_NAME')) {
    define('AIRCOINS_SESSION_NAME', 'AIRCOINS_ADMIN');
}

/** Idle timeout in seconds before an admin session is force-expired. */
if (!defined('AIRCOINS_IDLE_TIMEOUT')) {
    define('AIRCOINS_IDLE_TIMEOUT', 900);
}

/** Maximum failed login attempts allowed per rate-limit window. */
if (!defined('AIRCOINS_RATE_MAX')) {
    define('AIRCOINS_RATE_MAX', 5);
}

/** Rate-limit window in seconds. */
if (!defined('AIRCOINS_RATE_WINDOW')) {
    define('AIRCOINS_RATE_WINDOW', 300);
}
