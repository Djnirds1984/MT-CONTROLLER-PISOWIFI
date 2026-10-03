<?php
/**
 * AIRCOINS NETFI — RouterOS Legacy binary API client (v6 + v7).
 *
 * Implements the RouterOS binary "sentence" protocol over a raw TCP (8728) or
 * TLS (8729) socket. Each sentence is a sequence of length-prefixed words
 * terminated by a zero-length word; replies are tagged !re (record),
 * !done (end), !trap (error) or !fatal (fatal error).
 *
 * Login is dual-mode: modern firmware (>= 6.43) accepts a plaintext
 * "/login =name= =password=", while older firmware answers with a challenge
 * (=ret=<hex>) that must be met with "00" + md5(0x00 . password . challenge).
 *
 * The length codec (encodeLength/decodeLength) is PUBLIC STATIC so it can be
 * unit-tested with a round-trip without opening a socket.
 */

declare(strict_types=1);

require_once __DIR__ . '/RouterClientInterface.php';

class LegacyApiClient implements RouterClient
{
    /** @var resource|null Socket handle. */
    private $sock = null;

    private string $host;
    private int $port;
    private string $username;
    private string $password;
    private bool $tlsVerify;

    /**
     * Connect and authenticate immediately.
     *
     * @param array $router Router config: host, api_port, username,
     *                      password (plaintext), tls_verify.
     * @throws RuntimeException When the socket or login fails.
     */
    public function __construct(array $router)
    {
        $this->host      = (string) ($router['host'] ?? '');
        $this->port      = (int) ($router['api_port'] ?? 8728);
        $this->username  = (string) ($router['username'] ?? '');
        $this->password  = (string) ($router['password'] ?? '');
        $this->tlsVerify = !empty($router['tls_verify']);

        $this->connect();
        $this->login();
    }

    public function __destruct()
    {
        $this->close();
    }

    /** Close the socket if it is open. */
    public function close(): void
    {
        if (is_resource($this->sock)) {
            @fclose($this->sock);
        }
        $this->sock = null;
    }

    // ---------------------------------------------------------------------
    // Connection + login
    // ---------------------------------------------------------------------

    private function connect(): void
    {
        $useSsl    = ($this->port === 8729);
        $transport = $useSsl ? 'ssl' : 'tcp';
        $remote    = sprintf('%s://%s:%d', $transport, $this->host, $this->port);

        $context = stream_context_create();
        if ($useSsl) {
            stream_context_set_option($context, 'ssl', 'verify_peer', $this->tlsVerify);
            stream_context_set_option($context, 'ssl', 'verify_peer_name', $this->tlsVerify);
            if (!$this->tlsVerify) {
                stream_context_set_option($context, 'ssl', 'allow_self_signed', true);
            }
        }

        $errno  = 0;
        $errstr = '';
        $sock   = @stream_socket_client($remote, $errno, $errstr, 10.0, STREAM_CLIENT_CONNECT, $context);
        if ($sock === false) {
            throw new RuntimeException(sprintf('Legacy API connect failed to %s: [%d] %s', $remote, $errno, $errstr));
        }

        stream_set_timeout($sock, 10);
        $this->sock = $sock;
    }

    /**
     * Authenticate using the dual-mode handshake.
     *
     * @throws RuntimeException On !trap / !fatal or an unreadable reply.
     */
    private function login(): void
    {
        // Round 1: plaintext login (works on RouterOS >= 6.43).
        $this->writeSentence(['/login', '=name=' . $this->username, '=password=' . $this->password]);

        $challenge = null;
        while (true) {
            $words = $this->readSentence();
            if ($words === []) {
                continue;
            }
            $tag  = $words[0];
            $attr = self::parseSentence($words);

            if ($tag === '!re') {
                if (isset($attr['ret']) && $attr['ret'] !== '') {
                    $challenge = (string) $attr['ret'];
                }
                continue;
            }
            if ($tag === '!done') {
                break;
            }
            if ($tag === '!trap') {
                // Older firmware rejects the plaintext attempt and expects a
                // challenge-response; if a challenge arrived we handle it below,
                // otherwise this is a genuine auth failure.
                if ($challenge === null) {
                    throw new RuntimeException('Legacy API login failed: ' . ($attr['message'] ?? 'authentication error'));
                }
                break;
            }
            if ($tag === '!fatal') {
                throw new RuntimeException('Legacy API login fatal error: ' . ($attr['message'] ?? 'fatal'));
            }
        }

        if ($challenge === null) {
            return; // plaintext login accepted
        }

        // Round 2: challenge-response for pre-6.43 firmware.
        $bin      = hex2bin($challenge);
        if ($bin === false) {
            throw new RuntimeException('Legacy API login: malformed challenge from router.');
        }
        $response = '00' . md5("\x00" . $this->password . $bin);
        $this->writeSentence(['/login', '=name=' . $this->username, '=response=' . $response]);

        while (true) {
            $words = $this->readSentence();
            if ($words === []) {
                continue;
            }
            $tag  = $words[0];
            $attr = self::parseSentence($words);
            if ($tag === '!done') {
                return;
            }
            if ($tag === '!trap') {
                throw new RuntimeException('Legacy API challenge-response login failed: ' . ($attr['message'] ?? 'bad credentials'));
            }
            if ($tag === '!fatal') {
                throw new RuntimeException('Legacy API login fatal error: ' . ($attr['message'] ?? 'fatal'));
            }
        }
    }

    // ---------------------------------------------------------------------
    // Length codec (public static — unit-testable)
    // ---------------------------------------------------------------------

    /**
     * Encode a word length per the RouterOS binary API spec.
     *
     *  1 byte : len < 0x80
     *  2 bytes: len < 0x4000     (first byte |= 0x80)
     *  3 bytes: len < 0x200000   (first byte |= 0xC0)
     *  4 bytes: len < 0x10000000 (first byte |= 0xE0)
     *  5 bytes: otherwise        (first byte = 0xF0, then 4 raw bytes)
     *
     * @param int $len Non-negative byte length.
     * @return string Encoded length prefix.
     * @throws RuntimeException On a negative length.
     */
    public static function encodeLength(int $len): string
    {
        if ($len < 0) {
            throw new RuntimeException('encodeLength: negative length.');
        }
        if ($len < 0x80) {
            return chr($len);
        }
        if ($len < 0x4000) {
            return pack('n', $len | 0x8000);
        }
        if ($len < 0x200000) {
            return chr((($len >> 16) & 0xFF) | 0xC0) . chr(($len >> 8) & 0xFF) . chr($len & 0xFF);
        }
        if ($len < 0x10000000) {
            return pack('N', $len | 0xE0000000);
        }
        return chr(0xF0) . pack('N', $len);
    }

    /**
     * Decode a length prefix from a socket (inverse of encodeLength).
     *
     * @param resource $sock Open socket.
     * @return int Decoded byte length.
     * @throws RuntimeException On a short read or timeout.
     */
    public static function decodeLength($sock): int
    {
        $b = ord(self::readN($sock, 1));

        if ($b < 0x80) {
            return $b;
        }
        if ($b < 0xC0) {
            $b2 = ord(self::readN($sock, 1));
            return (($b & 0x7F) << 8) | $b2;
        }
        if ($b < 0xE0) {
            $b2 = ord(self::readN($sock, 1));
            $b3 = ord(self::readN($sock, 1));
            return (($b & 0x3F) << 16) | ($b2 << 8) | $b3;
        }
        if ($b < 0xF0) {
            $b2 = ord(self::readN($sock, 1));
            $b3 = ord(self::readN($sock, 1));
            $b4 = ord(self::readN($sock, 1));
            return (($b & 0x1F) << 24) | ($b2 << 16) | ($b3 << 8) | $b4;
        }
        // 0xF0: next four bytes hold the raw 32-bit length.
        $b2 = ord(self::readN($sock, 1));
        $b3 = ord(self::readN($sock, 1));
        $b4 = ord(self::readN($sock, 1));
        $b5 = ord(self::readN($sock, 1));
        return ($b2 << 24) | ($b3 << 16) | ($b4 << 8) | $b5;
    }

    // ---------------------------------------------------------------------
    // Sentence I/O
    // ---------------------------------------------------------------------

    /**
     * Read exactly $n bytes, looping over partial reads.
     *
     * @param resource $sock Open socket.
     * @param int      $n    Number of bytes to read.
     * @return string The bytes read.
     * @throws RuntimeException On timeout, EOF or short read.
     */
    private static function readN($sock, int $n): string
    {
        if ($n === 0) {
            return '';
        }
        $buf       = '';
        $remaining = $n;
        while ($remaining > 0) {
            $chunk = fread($sock, $remaining);
            if ($chunk === false || $chunk === '') {
                $meta = stream_get_meta_data($sock);
                if (!empty($meta['timed_out'])) {
                    throw new RuntimeException('Legacy API read timed out.');
                }
                if (feof($sock)) {
                    throw new RuntimeException('Legacy API connection closed by router.');
                }
                throw new RuntimeException('Legacy API short read.');
            }
            $buf       .= $chunk;
            $remaining -= strlen($chunk);
        }
        return $buf;
    }

    /**
     * Write a sentence: each word length-prefixed, terminated by a zero byte.
     *
     * @param array<int,string> $words Words to send (command + attributes).
     * @throws RuntimeException On write failure.
     */
    public function writeSentence(array $words): void
    {
        $out = '';
        foreach ($words as $w) {
            $w   = (string) $w;
            $out .= self::encodeLength(strlen($w)) . $w;
        }
        $out .= "\x00"; // zero-length terminator ends the sentence
        $this->writeAll($out);
    }

    private function writeAll(string $data): void
    {
        $len     = strlen($data);
        $written = 0;
        while ($written < $len) {
            $n = fwrite($this->sock, substr($data, $written));
            if ($n === false || $n === 0) {
                $meta = is_resource($this->sock) ? stream_get_meta_data($this->sock) : [];
                if (!empty($meta['timed_out'])) {
                    throw new RuntimeException('Legacy API write timed out.');
                }
                throw new RuntimeException('Legacy API write failed.');
            }
            $written += $n;
        }
    }

    /**
     * Read one sentence (list of words) until the zero-length terminator.
     *
     * @return array<int,string> Words in the sentence.
     * @throws RuntimeException On read failure.
     */
    public function readSentence(): array
    {
        $words = [];
        while (true) {
            $len = self::decodeLength($this->sock);
            if ($len === 0) {
                break;
            }
            $words[] = self::readN($this->sock, $len);
        }
        return $words;
    }

    /**
     * Parse a reply sentence into an associative attribute map.
     *
     * The first word (the reply tag) is skipped; subsequent "=key=value" words
     * become entries. Values may themselves contain '='.
     *
     * @param array<int,string> $words Reply words.
     * @return array<string,string> Attribute map.
     */
    public static function parseSentence(array $words): array
    {
        $out = [];
        foreach ($words as $i => $w) {
            if ($i === 0) {
                continue;
            }
            $w = (string) $w;
            if (strncmp($w, '=', 1) !== 0) {
                continue;
            }
            $body = substr($w, 1);
            $eq   = strpos($body, '=');
            if ($eq === false) {
                continue;
            }
            $out[substr($body, 0, $eq)] = substr($body, $eq + 1);
        }
        return $out;
    }

    /**
     * Send a command and collect its records.
     *
     * @param string               $command RouterOS command (e.g. "/ip/hotspot/user/print").
     * @param array<string,string> $attrs   "=key=value" attributes.
     * @param array<string,string> $queries "?key=value" query filters.
     * @return array<int,array<string,string>> The !re records.
     * @throws RuntimeException On !trap or !fatal.
     */
    public function cmd(string $command, array $attrs = [], array $queries = []): array
    {
        return $this->exec($command, $attrs, $queries)['records'];
    }

    /**
     * Internal command runner returning both records and the !done attributes.
     *
     * @param string               $command RouterOS command.
     * @param array<string,string> $attrs   Attributes.
     * @param array<string,string> $queries Query filters.
     * @return array{records:array<int,array<string,string>>,done:array<string,string>}
     * @throws RuntimeException On !trap or !fatal.
     */
    private function exec(string $command, array $attrs = [], array $queries = []): array
    {
        $words = [$command];
        foreach ($attrs as $k => $v) {
            $words[] = '=' . $k . '=' . $v;
        }
        foreach ($queries as $k => $v) {
            $words[] = '?' . $k . '=' . $v;
        }
        $this->writeSentence($words);

        $records = [];
        $done    = [];
        while (true) {
            $reply = $this->readSentence();
            if ($reply === []) {
                continue;
            }
            $tag  = $reply[0];
            $attr = self::parseSentence($reply);

            if ($tag === '!re') {
                $records[] = $attr;
                continue;
            }
            if ($tag === '!done') {
                $done = $attr;
                break;
            }
            if ($tag === '!trap') {
                throw new RuntimeException('RouterOS trap on ' . $command . ': ' . ($attr['message'] ?? 'error'));
            }
            if ($tag === '!fatal') {
                throw new RuntimeException('RouterOS fatal on ' . $command . ': ' . ($attr['message'] ?? 'fatal error'));
            }
        }
        return ['records' => $records, 'done' => $done];
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
        $recs = $this->cmd('/system/identity/print');
        $r    = $recs[0] ?? [];
        return ['name' => (string) ($r['name'] ?? '')];
    }

    /** @inheritDoc */
    public function resource(): array
    {
        $recs = $this->cmd('/system/resource/print');
        $r    = $recs[0] ?? [];
        return [
            'cpu-load'     => (float) ($r['cpu-load'] ?? 0),
            'free-memory'  => (int) ($r['free-memory'] ?? 0),
            'total-memory' => (int) ($r['total-memory'] ?? 0),
            'uptime'       => self::parseUptime((string) ($r['uptime'] ?? '0')),
            'version'      => (string) ($r['version'] ?? ''),
            'board-name'   => (string) ($r['board-name'] ?? ''),
        ];
    }

    /** @inheritDoc */
    public function hotspotUsers(): array
    {
        $recs = $this->cmd('/ip/hotspot/user/print');
        $out  = [];
        foreach ($recs as $r) {
            $out[] = [
                '.id'          => (string) ($r['.id'] ?? ''),
                'name'         => (string) ($r['name'] ?? ''),
                'profile'      => (string) ($r['profile'] ?? ''),
                'comment'      => (string) ($r['comment'] ?? ''),
                                'limit-uptime' => (string) ($r['limit-uptime'] ?? ''),
                'disabled'     => self::toBool($r['disabled'] ?? 'false'),
            ];
        }
        return $out;
    }

    /** @inheritDoc */
    public function addHotspotUser(string $name, string $pass, string $profile, string $comment = '', string $uptimeLimit = ''): array
    {
        $attrs = ['name' => $name, 'password' => $pass];
        if ($profile !== '') {
            $attrs['profile'] = $profile;
        }
        if ($comment !== '') {
            $attrs['comment'] = $comment;
        }
        if ($uptimeLimit !== '') {
            $attrs['limit-uptime'] = $uptimeLimit;
        }
        $res = $this->exec('/ip/hotspot/user/add', $attrs);
        $id  = (string) ($res['done']['ret'] ?? $res['done']['.id'] ?? '');
        return [
            '.id'      => $id,
            'name'     => $name,
            'profile'  => $profile,
            'comment'  => $comment,
            'disabled' => false,
        ];
    }

    /** @inheritDoc */
    public function deleteHotspotUser(string $id): bool
    {
        $this->exec('/ip/hotspot/user/remove', ['.id' => $id]);
        return true;
    }

    /** @inheritDoc */
    public function hotspotProfiles(): array
    {
        $recs = $this->cmd('/ip/hotspot/user/profile/print');
        $out  = [];
        foreach ($recs as $r) {
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
        $add = ['name' => (string) ($attrs['name'] ?? '')];
        $optional = ['rate-limit', 'session-timeout', 'uptime-limit', 'shared-users', 'idle-timeout'];
        foreach ($optional as $key) {
            if (!empty($attrs[$key])) {
                $add[$key] = (string) $attrs[$key];
            }
        }
        $res = $this->exec('/ip/hotspot/user/profile/add', $add);
        $id  = (string) ($res['done']['ret'] ?? $res['done']['.id'] ?? '');
        return [
            '.id'  => $id,
            'name' => $add['name'],
        ];
    }

    /** @inheritDoc */
    public function deleteHotspotProfile(string $id): bool
    {
        $this->exec('/ip/hotspot/user/profile/remove', ['.id' => $id]);
        return true;
    }

    /** @inheritDoc */
    public function activeSessions(): array
    {
        $recs = $this->cmd('/ip/hotspot/active/print');
        $out  = [];
        foreach ($recs as $r) {
            $out[] = self::mapSession($r);
        }
        return $out;
    }

    /** @inheritDoc */
    public function dhcpLeases(): array
    {
        $recs = $this->cmd('/ip/dhcp-server/lease/print');
        $out  = [];
        foreach ($recs as $r) {
            $out[] = [
                'mac-address'  => (string) ($r['mac-address'] ?? ''),
                'address'      => (string) ($r['address'] ?? ''),
                'host-name'    => (string) ($r['host-name'] ?? ''),
                'server'       => (string) ($r['server'] ?? ''),
                'status'       => (string) ($r['status'] ?? ''),
                'expires-after'=> (string) ($r['expires-after'] ?? ''),
                'last-seen'    => (string) ($r['last-seen'] ?? ''),
            ];
        }
        return $out;
    }

    /** @inheritDoc */
    public function kickSession(string $id): bool
    {
        $this->exec('/ip/hotspot/active/remove', ['.id' => $id]);
        return true;
    }

    /** @inheritDoc */
    public function interfaces(): array
    {
        $recs = $this->cmd('/interface/print');
        $out  = [];
        foreach ($recs as $r) {
            $out[] = [
                '.id'     => (string) ($r['.id'] ?? ''),
                'name'    => (string) ($r['name'] ?? ''),
                'type'    => (string) ($r['type'] ?? ''),
                'running' => self::toBool($r['running'] ?? 'false'),
                'rx-byte' => (int) ($r['rx-byte'] ?? 0),
                'tx-byte' => (int) ($r['tx-byte'] ?? 0),
            ];
        }
        return $out;
    }

    /** @inheritDoc */
    public function findActiveByMac(string $mac): ?array
    {
        $recs = $this->cmd('/ip/hotspot/active/print', [], ['mac' => $mac]);
        if ($recs === []) {
            return null;
        }
        return self::mapSession($recs[0]);
    }

    // ---------------------------------------------------------------------
    // Normalisation helpers
    // ---------------------------------------------------------------------

    /**
     * Map a raw active-session record to the RouterClient session shape.
     *
     * @param array<string,string> $r Raw record.
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
     * Coerce RouterOS boolean-ish strings ("true"/"yes") to bool.
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
     * @param string $raw Raw uptime (integer seconds or "6w5d4h3m2s").
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
        // The legacy binary API has no file-write command (/file is read-only),
        // but it CAN find and remove files. Strategy: delete any existing file
        // via legacy, then create fresh via REST PUT (no "file already exists").

        // Step 1: Find the file's .id via legacy API (reliable).
        $fileId = null;
        try {
            $recs = $this->cmd('/file/print', [], ['name' => $path]);
            foreach ($recs as $r) {
                if ((string) ($r['name'] ?? '') === $path) {
                    $fileId = (string) ($r['.id'] ?? '');
                    break;
                }
            }
        } catch (\Throwable $e) {
            // Listing failed — continue to PUT attempt.
        }

        // Step 2: Delete via legacy so REST PUT won't hit "file already exists".
        if ($fileId !== null && $fileId !== '') {
            try {
                $this->cmd('/file/remove', ['.id' => $fileId]);
            } catch (\Throwable $e) {
                // Remove failed — continue anyway.
            }
        }

        // Step 3: Create fresh via REST PUT (file no longer exists).
        require_once __DIR__ . '/RestClient.php';
        $rest = new RestClient([
            'host'       => $this->host,
            'api_port'   => 80,
            'username'   => $this->username,
            'password'   => $this->password,
            'tls_verify' => false,
        ]);
        return $rest->uploadHotspotStub($path, $content);
    }

    /** @inheritDoc */
    public function listFiles(string $dir): array
    {
        $recs = $this->cmd('/file/print', [], ['name' => '~' . $dir]);
        $out  = [];
        foreach ($recs as $r) {
            $out[] = [
                'name' => (string) ($r['name'] ?? ''),
                'type' => (string) ($r['type'] ?? ''),
                'size' => (int) ($r['size'] ?? 0),
            ];
        }
        return $out;
    }
}
