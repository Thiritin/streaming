<?php

namespace App\Jobs\Server\Deprovision;

use App\Enum\ServerStatusEnum;
use App\Models\Server;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;

/**
 * Force deprovisions a server that has been marked for removal.
 * This job immediately deletes the server without waiting for users to disconnect.
 */
class RemovalConditionCheckerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly Server $server) {}

    public function handle(): void
    {
        // Should the server have been reactivated in the meantime, we can stop here.
        if ($this->server->status !== ServerStatusEnum::DEPROVISIONING) {
            return;
        }

        // Force clear the server - no longer wait for users to disconnect
        // This will immediately proceed with deletion regardless of usage
        Bus::chain([
            new DeleteDnsRecordJob($this->server),
            new DeleteVirtualMachineJob($this->server),
        ])->dispatch();
    }
}
