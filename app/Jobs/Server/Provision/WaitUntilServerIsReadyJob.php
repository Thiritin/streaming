<?php

namespace App\Jobs\Server\Provision;

use App\Models\Server;
use DateTime;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class WaitUntilServerIsReadyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 30;

    public int $backoff = 30;



    public function __construct(public readonly Server $server)
    {
    }

    public function handle(): void
    {
        $server = $this->server;

        if ($server->isReady() === true) {
            SetServerAvailableJob::dispatch($this->server);
            return;
        }

        throw new \Exception('Server is not ready yet');
    }
}
