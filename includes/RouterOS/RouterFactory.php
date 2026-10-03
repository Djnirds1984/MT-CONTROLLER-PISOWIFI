<?php
/**
 * AIRCOINS NETFI — router client factory.
 *
 * Builds the correct RouterClient implementation for a stored router row,
 * decrypting the password (pass_enc) in memory only. No plaintext password is
 * ever persisted by this layer.
 */

declare(strict_types=1);

require_once __DIR__ . '/RouterClientInterface.php';
require_once __DIR__ . '/RestClient.php';
require_once __DIR__ . '/LegacyApiClient.php';
require_once __DIR__ . '/../crypto.php';

/**
 * Instantiate a RouterClient for the given router configuration.
 *
 * Accepts a full routers table row (with pass_enc) or an already-decrypted
 * array carrying a plaintext 'password' key (handy for tests). When api_type
 * is 'rest' a RestClient is returned; otherwise a LegacyApiClient.
 *
 * @param array $router Router config/row.
 * @return RouterClient The connected client.
 * @throws RuntimeException When the password cannot be decrypted or the
 *                          client cannot connect/authenticate.
 */
function aircoins_router_client(array $router): RouterClient
{
    // Resolve the plaintext password: prefer decrypting pass_enc; fall back to
    // an explicitly supplied plaintext 'password' (test/one-off usage).
    $password = '';
    if (!empty($router['pass_enc'])) {
        $password = aircoins_decrypt((string) $router['pass_enc']);
    } elseif (isset($router['password'])) {
        $password = (string) $router['password'];
    }

    $config = [
        'host'       => (string) ($router['host'] ?? ''),
        'api_port'   => (int) ($router['api_port'] ?? 0),
        'username'   => (string) ($router['username'] ?? ''),
        'password'   => $password,
        'tls_verify' => !empty($router['tls_verify']),
    ];

    $type = strtolower((string) ($router['api_type'] ?? 'rest'));

    if ($type === 'rest') {
        return new RestClient($config);
    }

    return new LegacyApiClient($config);
}
