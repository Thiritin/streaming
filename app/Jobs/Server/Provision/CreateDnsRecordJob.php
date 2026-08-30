<?php

namespace App\Jobs\Server\Provision;

use App\Models\Server;
use App\Services\Dns\DnsManager;
use App\Services\Dns\DnsProvider;
use App\Services\Dns\DnsRecord;
use App\Support\DnsSettings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CreateDnsRecordJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public readonly Server $server) {}

    public function handle(DnsManager $dns): void
    {
        // Origins get a record the same as edges: both are addressed by hostname and
        // both take a certificate for it.
        $provider = $dns->driver();

        // A record with no address is worse than no record: the name exists and answers
        // nothing. The poll before this is what fails on it; this is the guard, not the
        // report.
        if (blank($this->server->hostname) || blank($this->server->ip)) {
            Log::warning('No DNS record written: the server has no hostname or no address', [
                'server_id' => $this->server->id,
                'hostname' => $this->server->hostname,
                'ip' => $this->server->ip,
            ]);

            return;
        }

        $record = new DnsRecord(
            hostname: $this->server->hostname,
            value: $this->server->ip,
            ttl: DnsSettings::ttl(),
        );

        try {
            $provider->upsert($record);

            // Which provider wrote it, and into which zone. The delete resolves the
            // driver named here rather than the one selected now, or switching provider
            // would make every existing record undeleteable.
            $this->server->rememberDnsProvider($provider->name(), $provider->zone());

            Log::info('DNS record created', [
                'hostname' => $record->hostname,
                'ip' => $record->value,
                'provider' => $provider->name(),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to create DNS record', [
                'hostname' => $record->hostname,
                'ip' => $record->value,
                'provider' => $provider->name(),
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        $this->verify($provider, $record);
    }

    /**
     * Ask the authoritative server what it now holds, once, and only log.
     *
     * Not a retry loop and deliberately not a throw. Without it the first thing that
     * notices a record never landed is WaitUntilServerIsReadyJob failing thirty times
     * over fifteen minutes for a reason it cannot name; turning a propagation delay
     * into an aborted provision would be worse than either.
     */
    private function verify(DnsProvider $provider, DnsRecord $record): void
    {
        try {
            $answer = $provider->resolve($record->hostname);
        } catch (\Throwable $e) {
            Log::warning('Could not read the DNS record back', [
                'hostname' => $record->hostname,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        if ($answer !== $record->value) {
            Log::warning('DNS record does not read back as written', [
                'hostname' => $record->hostname,
                'expected' => $record->value,
                'actual' => $answer,
            ]);
        }
    }
}
