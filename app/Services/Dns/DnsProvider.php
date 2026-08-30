<?php

namespace App\Services\Dns;

use App\Services\DriverCheck;

interface DnsProvider
{
    /**
     * Idempotent: the name ends up pointing at exactly this value and nothing else.
     *
     * Never a bare add. Two chains reach this for the same server, and an additive
     * write leaves two A records for one hostname - resolvers then round-robin between
     * a live box and an address that was reassigned to somebody else.
     */
    public function upsert(DnsRecord $record): void;

    /**
     * Already absent is success, the same way a 404 is for a deleted VM.
     */
    public function delete(DnsRecord $record): void;

    /**
     * What the authoritative server answers, not what a recursive resolver has caught
     * up with. Propagation is not something a driver controls or should report on.
     */
    public function resolve(string $hostname): ?string;

    /**
     * Credentials present, zone reachable, updates permitted.
     */
    public function check(): DriverCheck;

    public function zone(): string;

    public function name(): string;
}
