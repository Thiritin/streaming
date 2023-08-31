<?php

namespace App\Jobs\Server;

use App\Jobs\CheckClientActivityJob;
use App\Jobs\Server\Deprovision\DeleteDnsRecordJob;
use App\Jobs\Server\Deprovision\DeleteVirtualMachineJob;
use App\Jobs\Server\Deprovision\InitializeDeprovisioningJob;
use App\Jobs\Server\Deprovision\RemovalConditionCheckerJob;
use App\Jobs\Server\Deprovision\ServerMoveClientsToOtherServerJob;
use App\Models\Server;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;

class DeleteServerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(private readonly Server $server)
    {
    }

    public function handle(): void
    {
        Bus::chain([
            new InitializeDeprovisioningJob($this->server),
            new CheckClientActivityJob($this->server),
            new ServerMoveClientsToOtherServerJob($this->server),
            (new CheckClientActivityJob($this->server))->delay(now()->addMinute()),
            (new RemovalConditionCheckerJob($this->server))->delay(now()->addMinutes(5)),
        ])->dispatch();
    }
}
