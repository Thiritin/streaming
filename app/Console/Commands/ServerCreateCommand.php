<?php

namespace App\Console\Commands;

use App\Enum\ServerStatusEnum;
use App\Enum\ServerTypeEnum;
use App\Jobs\Server\CreateServerJob;
use App\Jobs\Server\Provision\CreateDnsRecordJob;
use App\Jobs\Server\Provision\CreateVirtualMachineJob;
use App\Jobs\Server\Provision\SetServerAvailableJob;
use App\Jobs\Server\Provision\WaitUntilServerIsReadyJob;
use App\Models\Server;
use Illuminate\Console\Command;
use Illuminate\Queue\Jobs\Job;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;

class ServerCreateCommand extends Command
{
    protected $signature = 'server:create';

    protected $description = 'Command description';

    public function handle()
    {
        CreateServerJob::dispatch();
    }
}
