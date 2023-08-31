<?php

namespace App\Jobs\Server\Deprovision;

use App\Enum\ServerStatusEnum;
use App\Models\Server;
use App\Services\Hetzner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DeleteVirtualMachineJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(readonly private Server $server)
    {
    }

    public function handle(): void
    {
        if (empty($this->server->hetzner_id)) {
            return;
        }

        $client = Hetzner::client();
        $client->servers()->getById($this->server->hetzner_id)->delete();
        $server = $this->server;
        $server->update([
            'status' => ServerStatusEnum::DELETED,
        ]);
    }
}
