<?php

namespace App\Services\Dns\Drivers;

use App\Services\Dns\DnsProvider;
use App\Services\Dns\DnsRecord;
use App\Services\DnsKeyService;
use App\Services\DriverCheck;
use App\Services\ShellCommand;
use Illuminate\Support\Str;

/**
 * Dynamic updates over RFC2136, signed with a TSIG key, spoken by nsupdate.
 *
 * The zone is somebody else's BIND, so everything here is a shell-out: nsupdate to
 * write, dig to read back. Both are external binaries, which is why check() proves
 * they exist before it proves anything about the zone.
 */
final class Rfc2136Driver implements DnsProvider
{
    public function __construct(
        private readonly string $server,
        private readonly string $zone,
        private readonly string $keyName,
        private readonly string $keyAlgorithm,
        private readonly string $keySecret,
    ) {}

    public function name(): string
    {
        return 'rfc2136';
    }

    public function zone(): string
    {
        return $this->zone;
    }

    /**
     * `update replace`, never `update add`.
     *
     * An add is additive: two runs of the provisioning chain against the same hostname
     * left two A records, and a resolver then answered with the live box half the time
     * and a dead address the other half. Replace is one statement, atomic by
     * definition, and it does not fail differently when the name did not exist.
     */
    public function upsert(DnsRecord $record): void
    {
        $this->nsupdate(sprintf(
            'update replace %s %d %s %s',
            DnsKeyService::requireName($record->hostname, 'hostname'),
            $record->ttl,
            self::requireType($record->type),
            DnsKeyService::requireName($record->value, 'record value'),
        ));
    }

    public function delete(DnsRecord $record): void
    {
        $this->nsupdate(sprintf(
            'update delete %s %s',
            DnsKeyService::requireName($record->hostname, 'hostname'),
            self::requireType($record->type),
        ));
    }

    public function resolve(string $hostname): ?string
    {
        $output = trim(ShellCommand::execute(sprintf(
            'dig +short %s A @%s',
            escapeshellarg(DnsKeyService::requireName($hostname, 'hostname')),
            escapeshellarg(DnsKeyService::requireName($this->server, 'name server')),
        )));

        if ($output === '') {
            return null;
        }

        // dig answers one address per line; the first is enough to compare against.
        return trim(explode("\n", $output)[0]);
    }

    public function check(): DriverCheck
    {
        $details = [
            'Server' => $this->server,
            'Zone' => $this->zone,
            'Key' => $this->keyName.' ('.$this->keyAlgorithm.')',
        ];

        $keyService = $this->keyService();

        if ($static = $keyService->staticKeyFile()) {
            $details['Key file'] = 'Using '.$static.', not a saved secret';
        }

        try {
            DnsKeyService::requireName($this->server, 'name server');
            DnsKeyService::requireName($this->zone, 'zone');
        } catch (\InvalidArgumentException $e) {
            return DriverCheck::fail($e->getMessage(), $details);
        }

        try {
            $soa = trim(ShellCommand::execute(sprintf(
                'dig +short SOA %s @%s',
                escapeshellarg($this->zone),
                escapeshellarg($this->server),
            )));
        } catch (\Throwable $e) {
            return DriverCheck::fail('Could not reach the name server: '.$e->getMessage(), $details);
        }

        if ($soa === '') {
            return DriverCheck::fail('The name server holds no SOA for this zone.', $details);
        }

        $details['SOA'] = $soa;

        /*
         * Authenticating and being allowed to write are two different permissions, and
         * a key that passes the first and fails the second is the failure this zone has
         * actually had. So the check writes and then removes an A record - the type the
         * driver actually writes, because the common `grant <key> name *.zone A` policy
         * refuses everything else and would call a working key broken - under a random
         * name, so it cannot destroy a record somebody is using.
         */
        $probe = '_check-'.Str::lower(Str::random(12)).'.'.trim($this->zone, '.').'.';

        try {
            $keyService->executeNsupdate(
                sprintf("update add %s 0 A 192.0.2.1\nsend\nupdate delete %s A", $probe, $probe),
            );
        } catch (\Throwable $e) {
            return DriverCheck::fail('The key authenticated but the update was refused: '.$e->getMessage(), $details);
        }

        return DriverCheck::pass('Updates accepted.', $details);
    }

    /**
     * The record types this driver writes. A closed set rather than an escape, because
     * the value reaches nsupdate's script and there is no reason for it to be open.
     */
    private static function requireType(string $type): string
    {
        $type = strtoupper(trim($type));

        if (! in_array($type, ['A', 'AAAA', 'CNAME', 'TXT'], true)) {
            throw new \InvalidArgumentException("Unsupported DNS record type: {$type}");
        }

        return $type;
    }

    private function nsupdate(string $commands): void
    {
        $this->keyService()->executeNsupdate($commands);
    }

    private function keyService(): DnsKeyService
    {
        return new DnsKeyService(
            $this->keyName,
            $this->keyAlgorithm,
            $this->keySecret,
            $this->server,
            $this->zone,
        );
    }
}
