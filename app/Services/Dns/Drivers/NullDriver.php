<?php

namespace App\Services\Dns\Drivers;

use App\Services\Dns\DnsProvider;
use App\Services\Dns\DnsRecord;
use App\Services\DriverCheck;
use Illuminate\Support\Facades\Log;

/**
 * Writes nothing.
 *
 * For an installation whose hostnames already exist, and for local development, where
 * the delete job used to carry an `App::isLocal()` branch of its own. Branching on the
 * environment inside a job is a driver choice made in the wrong place: this is the same
 * decision, declared once.
 */
final class NullDriver implements DnsProvider
{
    /**
     * What this process was asked to write, so the create job's read-back sees what it
     * just handed over rather than a mismatch it can do nothing about.
     *
     * @var array<string, string|null>
     */
    private array $written = [];

    public function __construct(private readonly string $zone = '') {}

    public function name(): string
    {
        return 'none';
    }

    public function zone(): string
    {
        return $this->zone;
    }

    public function upsert(DnsRecord $record): void
    {
        $this->written[$record->hostname] = $record->value;

        Log::info('DNS is off; no record written', ['hostname' => $record->hostname, 'value' => $record->value]);
    }

    public function delete(DnsRecord $record): void
    {
        unset($this->written[$record->hostname]);

        Log::info('DNS is off; no record removed', ['hostname' => $record->hostname]);
    }

    public function resolve(string $hostname): ?string
    {
        return $this->written[$hostname] ?? null;
    }

    public function check(): DriverCheck
    {
        return DriverCheck::pass('No records are written. Hostnames have to exist already.');
    }
}
