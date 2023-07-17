<?php

namespace App\Jobs\Server\Provision;

use App\Enum\ServerStatusEnum;
use App\Enum\ServerTypeEnum;
use App\Jobs\ServerAssignmentJob;
use App\Models\Server;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SetServerAvailableJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public function __construct(public readonly Server $server)
    {
    }

    public function handle(): void
    {
        $server = $this->server;
        $server->update([
            'status' => ServerStatusEnum::ACTIVE,
        ]);

        if ($this->server->type === ServerTypeEnum::EDGE) {
            ServerAssignmentJob::dispatch();
        }
    }
}
