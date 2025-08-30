<?php

namespace App\Console\Commands;

use App\Jobs\Server\CreateServerJob;
use Illuminate\Console\Command;

class ServerCreateCommand extends Command
{
    protected $signature = 'server:create';

    protected $description = 'Command description';

    public function handle()
    {
        CreateServerJob::dispatch();
    }
}
