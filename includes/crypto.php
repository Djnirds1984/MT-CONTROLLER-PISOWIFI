<?php
/**
 * AIRCOINS NETFI — authenticated encryption for router credentials.
 *
 * Router passwords are stored encrypted at rest (routers.pass_enc) using
 * libsodium's XSalsa20-Poly1305 secret box. The 32-byte key lives outside the
 * web root at AIRCOINS_KEY and is created by the install script.
 *
 * IMPORTANT: this module never persists plaintext; RouterFactory decrypts only
 * in memory for the lifetime of a single API request.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

/**
 * Load (and cache) the 32-byte secretbox key from AIRCOINS_KEY.
 *
 * Accepts the key stored as raw 32 bytes, 64-char hex, or base64.
 *
 * @return string 32-byte binary key.
 * @throws RuntimeException If the file is missing/unreadable or malformed.
 */
function aircoins_key(): string
{
    static $key = null;
    if ($key !== null) {
        return $key;
    }

    if (!function_exists('sodium_crypto_secretbox')) {
        throw new RuntimeException('The sodium extension is required for AIRCOINS crypto.');
    }

    $path = (string) AIRCOINS_KEY;
    if (!is_file($path) || !is_readable($path)) {
        throw new RuntimeException('AIRCOINS key file missing or unreadable: ' . $path);
    }

    $raw = file_get_contents($path);
    if ($raw === false) {
        throw new RuntimeException('Unable to read AIRCOINS key file: ' . $path);
    }

    $key = aircoins_normalize_key($raw);
    return $key;
}

/**
 * Normalise a stored key into exactly SODIUM_CRYPTO_SECRETBOX_KEYBYTES bytes.
 *
 * @param string $raw Key material as read from disk.
 * @return string 32-byte binary key.
 * @throws RuntimeException If the material cannot be interpreted as a 32-byte key.
 */
function aircoins_normalize_key(string $raw): string
{
    $need = SODIUM_CRYPTO_SECRETBOX_KEYBYTES; // 32
    if (strlen($raw) === $need) {
        return $raw; // already raw binary
    }

    $trimmed = trim($raw);
    if (strlen($trimmed) === $need) {
        return $trimmed;
    }

    if (preg_match('/^[0-9a-fA-F]{64}$/', $trimmed) === 1) {
        $bin = hex2bin($trimmed);
        if ($bin !== false && strlen($bin) === $need) {
            return $bin;
        }
    }

    $b64 = base64_decode($trimmed, true);
    if ($b64 !== false && strlen($b64) === $need) {
        return $b64;
    }

    throw new RuntimeException('AIRCOINS key must be 32 bytes (raw, 64-char hex, or base64).');
}

/**
 * Encrypt plaintext, returning base64(nonce || ciphertext).
 *
 * @param string $plaintext Value to protect (e.g. a router password).
 * @return string Base64 payload safe for storage in routers.pass_enc.
 * @throws RuntimeException If sodium is unavailable.
 */
function aircoins_encrypt(string $plaintext): string
{
    if (!function_exists('sodium_crypto_secretbox')) {
        throw new RuntimeException('The sodium extension is required for AIRCOINS crypto.');
    }

    $key   = aircoins_key();
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES); // 24
    $ct    = sodium_crypto_secretbox($plaintext, $nonce, $key);

    return base64_encode($nonce . $ct);
}

/**
 * Decrypt a base64(nonce || ciphertext) payload produced by aircoins_encrypt().
 *
 * @param string $encoded Stored payload.
 * @return string Original plaintext.
 * @throws RuntimeException On malformed input, bad key, or tampered data.
 */
function aircoins_decrypt(string $encoded): string
{
    if (!function_exists('sodium_crypto_secretbox_open')) {
        throw new RuntimeException('The sodium extension is required for AIRCOINS crypto.');
    }

    $raw = base64_decode($encoded, true);
    if ($raw === false) {
        throw new RuntimeException('aircoins_decrypt: invalid base64 payload.');
    }

    $nonceLen = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
    if (strlen($raw) <= $nonceLen) {
        throw new RuntimeException('aircoins_decrypt: payload too short.');
    }

    $nonce = substr($raw, 0, $nonceLen);
    $ct    = substr($raw, $nonceLen);
    $key   = aircoins_key();

    $plain = sodium_crypto_secretbox_open($ct, $nonce, $key);
    if ($plain === false) {
        throw new RuntimeException('aircoins_decrypt: decryption failed (bad key or tampered data).');
    }

    return $plain;
}
