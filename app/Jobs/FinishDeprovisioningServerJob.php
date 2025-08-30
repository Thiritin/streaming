<?php

namespace App\Jobs;

use App\Enum\ServerStatusEnum;
use App\Models\Server;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class FinishDeprovisioningServerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private readonly Server $server) {}

    public function handle(): void
    {
        $this->server->update([
            'status' => ServerStatusEnum::DELETED,
        ]);
    }
}
