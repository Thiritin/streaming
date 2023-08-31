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
 * Any Server this job gets has already been marked for deprovisioning, this is a last check to make sure the server can be safely removed.
 */
class RemovalConditionCheckerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly Server $server)
    {
    }

    public function handle(): void
    {
        // Should the server have been reactivated in the meantime, we can stop here.
        if($this->server->status !== ServerStatusEnum::DEPROVISIONING) {
            return;
        }

        // Easy check: Is the server still in use? If not we can remove it.
        if (!$this->server->isInUse()) {
            Bus::chain([
                new DeleteDnsRecordJob($this->server),
                new DeleteVirtualMachineJob($this->server)
            ])->dispatch();
        } // Server is still in use, check back in 30 minutes.
        else {
            $this->release(now()->addMinutes(30));
        }
    }
}
