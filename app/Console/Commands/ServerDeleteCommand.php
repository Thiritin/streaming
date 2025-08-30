<?php

namespace App\Console\Commands;

use App\Jobs\Server\DeleteServerJob;
use App\Models\Server;
use Illuminate\Console\Command;

class ServerDeleteCommand extends Command
{
    protected $signature = 'server:delete {id}';

    protected $description = 'Command description';

    public function handle(): void
    {
        DeleteServerJob::dispatch(Server::findOrFail($this->argument('id')));
    }
}
