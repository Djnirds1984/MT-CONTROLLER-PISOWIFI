<?php
/**
 * AIRCOINS NETFI — small, dependency-free helper functions.
 *
 * These are used by both the admin UI and the API endpoints for escaping,
 * JSON responses and human-friendly formatting.
 */

declare(strict_types=1);

/**
 * Escape a string for safe HTML output.
 *
 * @param string|null $value Raw value (null is treated as an empty string).
 * @return string HTML-escaped value (ENT_QUOTES, UTF-8).
 */
function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Emit a JSON response and terminate the request.
 *
 * @param mixed $data   Payload to serialise.
 * @param int   $status HTTP status code (default 200).
 */
function aircoins_json(mixed $data, int $status = 200): void
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
    }
    $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    echo $json === false ? '{"error":"json_encode_failed"}' : $json;
    exit;
}

/**
 * Format a byte count as a human-readable string (B/KB/MB/GB/TB/PB).
 *
 * @param int|float $bytes Number of bytes.
 * @return string e.g. "1023 B", "1.5 KB", "2.34 MB".
 */
function fmt_bytes(int|float $bytes): string
{
    $value = (float) $bytes;
    if ($value < 0) {
        $value = 0.0;
    }
    $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
    $i = 0;
    while ($value >= 1024 && $i < count($units) - 1) {
        $value /= 1024;
        $i++;
    }
    if ($i === 0) {
        return ((int) $value) . ' ' . $units[$i];
    }
    $formatted = rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    return $formatted . ' ' . $units[$i];
}

/**
 * Format a duration in seconds as "Xd Xh Xm Xs".
 *
 * @param int $seconds Non-negative number of seconds.
 * @return string Human-readable duration.
 */
function fmt_uptime(int $seconds): string
{
    if ($seconds < 0) {
        $seconds = 0;
    }
    $d = intdiv($seconds, 86400);
    $h = intdiv($seconds % 86400, 3600);
    $m = intdiv($seconds % 3600, 60);
    $s = $seconds % 60;
    return $d . 'd ' . $h . 'h ' . $m . 'm ' . $s . 's';
}

/**
 * Fetch a router row by id, or null when it does not exist.
 *
 * @param PDO $pdo Database connection.
 * @param int $id  Router id.
 * @return array|null The router row, or null when not found.
 */
function aircoins_get_router(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM routers WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}
