<?php
/**
 * AIRCOINS NETFI — unified RouterOS client contract.
 *
 * Both the REST (RouterOS v7) and the Legacy binary API (v6 + v7) clients
 * implement this interface so the admin panel and the portal session API can
 * talk to any router through one consistent shape.
 *
 * Return-value conventions (identical across implementations):
 *   resource()      => ['cpu-load'=>float, 'free-memory'=>int, 'total-memory'=>int,
 *                       'uptime'=>int(seconds), 'version'=>string, 'board-name'=>string]
 *   identity()      => ['name'=>string]
 *   hotspotUsers()  => list of ['.id'=>string, 'name'=>string, 'profile'=>string,
 *                               'comment'=>string, 'disabled'=>bool]
 *   activeSessions()=> list of ['.id'=>string, 'user'=>string, 'mac'=>string,
 *                               'address'=>string, 'uptime'=>string,
 *                               'bytes-in'=>int, 'bytes-out'=>int]
 *   interfaces()    => list of ['.id'=>string, 'name'=>string, 'type'=>string,
 *                               'running'=>bool, 'rx-byte'=>int, 'tx-byte'=>int]
 *   testConnection()=> ['ok'=>bool, 'name'=>string, 'version'=>string, 'board-name'=>string]
 *
 * No namespace is used: files are loaded with require_once (no Composer), so a
 * flat global symbol keeps consumption trivial for the admin/ and api/ layers.
 */

declare(strict_types=1);

interface RouterClient
{
    /**
     * Probe reachability + credentials by reading identity and resources.
     *
     * @return array{ok:bool,name:string,version:string,board-name:string}
     * @throws RuntimeException When the router is unreachable or rejects auth.
     */
    public function testConnection(): array;

    /**
     * @return array{name:string} Router identity.
     */
    public function identity(): array;

    /**
     * @return array{cpu-load:float,free-memory:int,total-memory:int,uptime:int,version:string,board-name:string}
     */
    public function resource(): array;

    /**
     * @return array<int,array<string,mixed>> All hotspot users.
     */
    public function hotspotUsers(): array;

    /**
     * Create a hotspot user (voucher).
     *
     * @param string $name    Username.
     * @param string $pass    Password.
     * @param string $profile Hotspot profile name.
     * @param string $comment Optional comment.
     * @return array<string,mixed> The created user record.
     */
    public function addHotspotUser(string $name, string $pass, string $profile, string $comment = ''): array;

    /**
     * Remove a hotspot user by its router-assigned id (e.g. "*5").
     *
     * @param string $id Router .id.
     * @return bool True on success.
     */
    public function deleteHotspotUser(string $id): bool;

    /**
     * @return array<int,array<string,mixed>> Currently active hotspot sessions.
     */
    public function activeSessions(): array;

    /**
     * Disconnect an active session by its router-assigned id.
     *
     * @param string $id Router .id of the active entry.
     * @return bool True on success.
     */
    public function kickSession(string $id): bool;

    /**
     * @return array<int,array<string,mixed>> Router interfaces with traffic counters.
     */
    public function interfaces(): array;

    /**
     * Find the active session for a MAC address (used by the portal status API).
     *
     * @param string $mac Client MAC address.
     * @return array<string,mixed>|null The matching session or null when absent.
     */
    public function findActiveByMac(string $mac): ?array;
}
