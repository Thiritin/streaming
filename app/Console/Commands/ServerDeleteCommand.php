<?php

namespace App\Console\Commands;

use App\Jobs\Server\DeleteServerJob;
use App\Jobs\Server\Deprovision\DeleteDnsRecordJob;
use App\Jobs\Server\Deprovision\DeleteVirtualMachineJob;
use App\Jobs\Server\Deprovision\InitializeDeprovisioningJob;
use App\Jobs\Server\Deprovision\ServerMoveClientsToOtherServerJob;
use App\Jobs\Server\Provision\CreateDnsRecordJob;
use App\Jobs\Server\Provision\CreateVirtualMachineJob;
use App\Jobs\Server\Provision\SetServerAvailableJob;
use App\Jobs\Server\Provision\WaitUntilServerIsReadyJob;
use App\Models\Server;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;

class ServerDeleteCommand extends Command
{
    protected $signature = 'server:delete {id}';

    protected $description = 'Command description';

    public function handle(): void
    {
        DeleteServerJob::dispatch(Server::findOrFail($this->argument('id')));
    }
}
