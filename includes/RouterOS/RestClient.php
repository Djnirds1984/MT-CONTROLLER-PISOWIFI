<?php
/**
 * AIRCOINS NETFI — RouterOS v7 REST API client.
 *
 * Talks to the router's www-ssl service over HTTPS using cURL + HTTP Basic
 * auth and JSON bodies. REST verbs map to RouterOS operations:
 *   GET    path[?query]  -> print
 *   PUT    path + body   -> add      (201)
 *   PATCH  path/.id+body -> set
 *   DELETE path/.id      -> remove   (204)
 *   POST   path + body   -> command  (e.g. monitor-traffic with {"once":""})
 *
 * Two REST quirks are handled explicitly:
 *  - every value comes back as a string, so numerics are cast to satisfy the
 *    RouterClient contract;
 *  - `.id` values (e.g. "*5") are appended to paths RAW — the asterisk must
 *    never be percent-encoded.
 */

declare(strict_types=1);

require_once __DIR__ . '/RouterClientInterface.php';

class RestClient implements RouterClient
{
    private string $host;
    private int $port;
    private string $username;
    private string $password;
    private bool $tlsVerify;
    private string $base;

    /**
     * @param array $router Router config: host, api_port, username,
     *                      password (plaintext, decrypted by the factory),
     *                      tls_verify.
     */
    public function __construct(array $router)
    {
        $this->host      = (string) ($router['host'] ?? '');
        $this->port      = (int) ($router['api_port'] ?? 443);
        $this->username  = (string) ($router['username'] ?? '');
        $this->password  = (string) ($router['password'] ?? '');
        $this->tlsVerify = !empty($router['tls_verify']);

        $scheme     = ($this->port === 80) ? 'http' : 'https';
        $this->base = $scheme . '://' . $this->host . ':' . $this->port . '/rest';
    }

    // ---------------------------------------------------------------------
    // RouterClient implementation
    // ---------------------------------------------------------------------

    /** @inheritDoc */
    public function testConnection(): array
    {
        $id  = $this->identity();
        $res = $this->resource();
        return [
            'ok'         => true,
            'name'       => (string) ($id['name'] ?? ''),
            'version'    => (string) ($res['version'] ?? ''),
            'board-name' => (string) ($res['board-name'] ?? ''),
        ];
    }

    /** @inheritDoc */
    public function identity(): array
    {
        $d = $this->get('/system/identity');
        return ['name' => (string) ($d['name'] ?? '')];
    }

    /** @inheritDoc */
    public function resource(): array
    {
        $d = $this->get('/system/resource');
        return [
            'cpu-load'    => (float) ($d['cpu-load'] ?? 0),
            'free-memory' => (int) ($d['free-memory'] ?? 0),
            'total-memory' => (int) ($d['total-memory'] ?? 0),
            'uptime'      => self::parseUptime((string) ($d['uptime'] ?? '0')),
            'version'     => (string) ($d['version'] ?? ''),
            'board-name'  => (string) ($d['board-name'] ?? ''),
        ];
    }

    /** @inheritDoc */
    public function hotspotUsers(): array
    {
        $rows = $this->get('/ip/hotspot/user');
        $out  = [];
        foreach (self::asList($rows) as $r) {
            $out[] = [
                '.id'          => (string) ($r['.id'] ?? ''),
                'name'         => (string) ($r['name'] ?? ''),
                'profile'      => (string) ($r['profile'] ?? ''),
                'comment'      => (string) ($r['comment'] ?? ''),
                                'limit-uptime' => (string) ($r['limit-uptime'] ?? ''),
                'disabled'     => self::toBool($r['disabled'] ?? false),
            ];
        }
        return $out;
    }

    /** @inheritDoc */
    public function addHotspotUser(string $name, string $pass, string $profile, string $comment = '', string $uptimeLimit = ''): array
    {
        $body = ['name' => $name, 'password' => $pass, 'profile' => $profile];
        if ($comment !== '') {
            $body['comment'] = $comment;
        }
        if ($uptimeLimit !== '') {
            $body['limit-uptime'] = $uptimeLimit;
        }
        $d = $this->put('/ip/hotspot/user', $body);
        return [
            '.id'      => (string) ($d['.id'] ?? ''),
            'name'     => $name,
            'profile'  => $profile,
            'comment'  => $comment,
            'disabled' => false,
        ];
    }

    /** @inheritDoc */
    public function deleteHotspotUser(string $id): bool
    {
        // .id is appended RAW — the leading '*' must not be percent-encoded.
        $this->delete('/ip/hotspot/user/' . $id);
        return true;
    }

    /** @inheritDoc */
    public function hotspotProfiles(): array
    {
        $rows = $this->get('/ip/hotspot/user/profile');
        $out  = [];
        foreach (self::asList($rows) as $r) {
            $out[] = [
                '.id'              => (string) ($r['.id'] ?? ''),
                'name'             => (string) ($r['name'] ?? ''),
                'rate-limit'       => (string) ($r['rate-limit'] ?? ''),
                'session-timeout'  => (string) ($r['session-timeout'] ?? ''),
                'uptime-limit'     => (string) ($r['uptime-limit'] ?? ''),
                'shared-users'     => (string) ($r['shared-users'] ?? ''),
                'idle-timeout'     => (string) ($r['idle-timeout'] ?? ''),
            ];
        }
        return $out;
    }

    /** @inheritDoc */
    public function addHotspotProfile(array $attrs): array
    {
        $body = ['name' => (string) ($attrs['name'] ?? '')];
        $optional = ['rate-limit', 'session-timeout', 'uptime-limit', 'shared-users', 'idle-timeout'];
        foreach ($optional as $key) {
            if (!empty($attrs[$key])) {
                $body[$key] = (string) $attrs[$key];
            }
        }
        $d = $this->put('/ip/hotspot/user/profile', $body);
        return [
            '.id'  => (string) ($d['.id'] ?? ''),
            'name' => $body['name'],
        ];
    }

    /** @inheritDoc */
    public function deleteHotspotProfile(string $id): bool
    {
        $this->delete('/ip/hotspot/user/profile/' . $id);
        return true;
    }

    /** @inheritDoc */
    public function activeSessions(): array
    {
        $rows = $this->get('/ip/hotspot/active');
        $out  = [];
        foreach (self::asList($rows) as $r) {
            $out[] = self::mapSession($r);
        }
        return $out;
    }

    /** @inheritDoc */
    public function kickSession(string $id): bool
    {
        $this->delete('/ip/hotspot/active/' . $id);
        return true;
    }

    /** @inheritDoc */
    public function interfaces(): array
    {
        $rows = $this->get('/interface', '.proplist=.id,name,type,running,rx-byte,tx-byte');
        $out  = [];
        foreach (self::asList($rows) as $r) {
            $out[] = [
                '.id'     => (string) ($r['.id'] ?? ''),
                'name'    => (string) ($r['name'] ?? ''),
                'type'    => (string) ($r['type'] ?? ''),
                'running' => self::toBool($r['running'] ?? false),
                'rx-byte' => (int) ($r['rx-byte'] ?? 0),
                'tx-byte' => (int) ($r['tx-byte'] ?? 0),
            ];
        }
        return $out;
    }

    /** @inheritDoc */
    public function findActiveByMac(string $mac): ?array
    {
        $rows = $this->get('/ip/hotspot/active', 'mac=' . rawurlencode($mac));
        $list = self::asList($rows);
        if ($list === []) {
            return null;
        }
        return self::mapSession($list[0]);
    }

    // ---------------------------------------------------------------------
    // HTTP plumbing
    // ---------------------------------------------------------------------

    private function get(string $path, ?string $query = null): array
    {
        return $this->request('GET', $path, null, $query);
    }

    private function put(string $path, array $body, int $timeout = 10): array
    {
        return $this->request('PUT', $path, $body, null, $timeout);
    }

    private function patch(string $path, array $body): array
    {
        return $this->request('PATCH', $path, $body);
    }

    private function delete(string $path): array
    {
        return $this->request('DELETE', $path);
    }

    /**
     * Run a RouterOS command over POST (e.g. monitor-traffic once).
     *
     * @param string $path Endpoint path (without the /rest prefix).
     * @param array  $body JSON body.
     * @return array Decoded JSON response.
     */
    public function command(string $path, array $body): array
    {
        return $this->request('POST', $path, $body);
    }

    /**
     * Perform a single HTTP request against the router and decode the JSON.
     *
     * @param string      $method HTTP verb.
     * @param string      $path   Path relative to /rest (may contain a raw .id).
     * @param array|null  $body   Optional JSON body.
     * @param string|null $query  Optional pre-built query string.
     * @return array Decoded response (empty array when the body is empty).
     * @throws RuntimeException On transport failure or HTTP >= 400.
     */
    private function request(string $method, string $path, ?array $body = null, ?string $query = null, int $timeout = 10): array
    {
        $url = $this->base . '/' . ltrim($path, '/');
        if ($query !== null && $query !== '') {
            $url .= '?' . $query;
        }

        $ch = curl_init();
        if ($ch === false) {
            throw new RuntimeException('REST: unable to initialise cURL.');
        }

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Accept: application/json', 'Expect:']);
        curl_setopt($ch, CURLOPT_USERPWD, $this->username . ':' . $this->password);
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $this->tlsVerify);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $this->tlsVerify ? 2 : 0);

        if ($body !== null) {
            $payload = json_encode($body);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload === false ? '{}' : $payload);
        }

        $response = curl_exec($ch);
        if ($response === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('REST request to ' . $url . ' failed: ' . $err);
        }

        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        $raw     = (string) $response;
        $decoded = ($raw === '') ? [] : json_decode($raw, true);

        if ($status >= 400) {
            throw new RuntimeException(self::buildError($status, $decoded));
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Build a descriptive exception message from an error response.
     *
     * @param int   $status  HTTP status code.
     * @param mixed $decoded Decoded JSON body (may be array or null).
     * @return string Message including message/detail/error fields when present.
     */
    private static function buildError(int $status, mixed $decoded): string
    {
        $msg = 'REST error HTTP ' . $status;
        if (is_array($decoded)) {
            if (!empty($decoded['message'])) {
                $msg .= ': ' . $decoded['message'];
            }
            if (!empty($decoded['detail'])) {
                $msg .= ' (' . $decoded['detail'] . ')';
            }
            if (!empty($decoded['error'])) {
                $msg .= ' [' . $decoded['error'] . ']';
            }
        }
        return $msg;
    }

    // ---------------------------------------------------------------------
    // Normalisation helpers
    // ---------------------------------------------------------------------

    /**
     * Ensure a decoded REST response is a list of rows.
     *
     * REST list endpoints return a JSON array; a single object is wrapped.
     *
     * @param mixed $rows Decoded response.
     * @return array<int,array<string,mixed>>
     */
    private static function asList(mixed $rows): array
    {
        if (!is_array($rows)) {
            return [];
        }
        // Empty array -> empty list.
        if ($rows === []) {
            return [];
        }
        // Associative single object -> wrap into a one-element list.
        if (!self::isList($rows)) {
            return [$rows];
        }
        $out = [];
        foreach ($rows as $r) {
            if (is_array($r)) {
                $out[] = $r;
            }
        }
        return $out;
    }

    /**
     * Map a raw active-session row to the RouterClient session shape.
     *
     * @param array<string,mixed> $r Raw row.
     * @return array<string,mixed>
     */
    private static function mapSession(array $r): array
    {
        return [
            '.id'       => (string) ($r['.id'] ?? ''),
            'user'      => (string) ($r['user'] ?? ''),
            'mac'       => (string) ($r['mac-address'] ?? $r['mac'] ?? ''),
            'address'   => (string) ($r['address'] ?? ''),
            'uptime'    => (string) ($r['uptime'] ?? ''),
            'bytes-in'  => (int) ($r['bytes-in'] ?? 0),
            'bytes-out' => (int) ($r['bytes-out'] ?? 0),
        ];
    }

    /**
     * PHP 8.0-safe replacement for array_is_list().
     *
     * @param array $arr Array to test.
     * @return bool True when keys are a contiguous 0-based sequence.
     */
    private static function isList(array $arr): bool
    {
        $i = 0;
        foreach ($arr as $k => $_) {
            if ($k !== $i) {
                return false;
            }
            $i++;
        }
        return true;
    }

    /**
     * Coerce RouterOS boolean-ish values ("true"/"false"/"yes") to bool.
     *
     * @param mixed $v Raw value.
     * @return bool
     */
    private static function toBool(mixed $v): bool
    {
        if (is_bool($v)) {
            return $v;
        }
        $s = strtolower(trim((string) $v));
        return in_array($s, ['true', 'yes', '1'], true);
    }

    /**
     * Parse a RouterOS uptime value into whole seconds.
     *
     * Accepts a plain integer (already seconds) or a duration string such as
     * "6w5d4h3m2s".
     *
     * @param string $raw Raw uptime.
     * @return int Seconds.
     */
    private static function parseUptime(string $raw): int
    {
        $raw = trim($raw);
        if ($raw === '') {
            return 0;
        }
        if (ctype_digit($raw)) {
            return (int) $raw;
        }
        $map   = ['w' => 604800, 'd' => 86400, 'h' => 3600, 'm' => 60, 's' => 1];
        $total = 0;
        if (preg_match_all('/(\d+)\s*([wdhms])/', $raw, $m, PREG_SET_ORDER) > 0) {
            foreach ($m as $part) {
                $unit = $part[2];
                if (isset($map[$unit])) {
                    $total += ((int) $part[1]) * $map[$unit];
                }
            }
        }
        return $total;
    }

    /** @inheritDoc */
    public function uploadHotspotStub(string $path, string $content): bool
    {
        // PUT /rest/file is ADD-only — it fails when the file already exists.
        // Strategy: find the file's .id; PATCH its contents if found, PUT if not.
        $encoded = base64_encode($content);
        $fileId  = $this->findFileId($path);

        if ($fileId !== null) {
            // File exists — overwrite contents via PATCH.
            $this->request('PATCH', '/file/' . $fileId, ['contents' => $encoded], null, 30);
        } else {
            // File does not exist yet — create via PUT.
            $this->request('PUT', '/file', ['name' => $path, 'contents' => $encoded], null, 30);
        }
        return true;
    }

    /**
     * Look up a file's RouterOS .id by its full name/path.
     *
     * @param string $name Full file path (e.g. "flash/hotspot/login.html").
     * @return string|null The .id (e.g. "*5") or null when not found.
     */
    private function findFileId(string $name): ?string
    {
        try {
            $rows = $this->get('/file');
            foreach (self::asList($rows) as $r) {
                if ((string) ($r['name'] ?? '') === $name) {
                    $id = (string) ($r['.id'] ?? '');
                    return $id !== '' ? $id : null;
                }
            }
        } catch (Throwable $e) {
            // Listing failed — fall through to PUT attempt.
        }
        return null;
    }

    /** @inheritDoc */
    public function listFiles(string $dir): array
    {
        // RouterOS REST /file does not support regex query filters reliably;
        // fetch all files and filter client-side.
        $rows = $this->get('/file');
        $out  = [];
        $prefix = $dir . '/';
        foreach (self::asList($rows) as $r) {
            $name = (string) ($r['name'] ?? '');
            if ($name !== '' && (strpos($name, $prefix) === 0 || $name === $dir)) {
                $out[] = [
                    'name' => $name,
                    'type' => (string) ($r['type'] ?? ''),
                    'size' => (int) ($r['size'] ?? 0),
                ];
            }
        }
        return $out;
    }
}
