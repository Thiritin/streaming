<?php

namespace App\Jobs\Server\Deprovision;

use App\Models\Server;
use App\Services\Dns\DnsManager;
use App\Services\Dns\DnsRecord;
use App\Support\DnsSettings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class DeleteDnsRecordJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public readonly Server $server) {}

    public function handle(DnsManager $dns): void
    {
        // Origins used to return early here, which leaked a record on every teardown.
        // CreateDnsRecordJob makes an A record for an origin the same as for an edge, so
        // skipping the delete was simply asymmetric: origin-1 was torn down in September
        // 2025 and its hostname still resolved eleven months later, by which point
        // Hetzner had long since reassigned that address to somebody else. A name under
        // our zone pointing at a stranger's server is worth more than an untidy record.

        // The driver the record was written by, not the one selected now. Switching
        // provider must not strand the fleet's existing names.
        $provider = $dns->forRecord($this->server->dnsProvider());

        $record = new DnsRecord(
            hostname: $this->server->hostname,
            ttl: DnsSettings::ttl(),
        );

        try {
            $provider->delete($record);

            Log::info('DNS record deleted', [
                'hostname' => $record->hostname,
                'provider' => $provider->name(),
            ]);
        } catch (\Exception $e) {
            // Logged, not rethrown. This job runs before DeleteVirtualMachineJob in the
            // chain, so throwing here aborted the chain and left the cloud VM running
            // and billing - a dead nsupdate key was enough to make every teardown in the
            // fleet leak a server. A stale A record is the cheaper of the two failures,
            // and it is visible in the log rather than silent.
            Log::error('Failed to delete DNS record; continuing with teardown', [
                'hostname' => $record->hostname,
                'provider' => $provider->name(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
