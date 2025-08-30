<?php

namespace App\Jobs\Server\Deprovision;

use App\Models\Client;
use App\Models\Server;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ServerMoveClientsToOtherServerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(private readonly Server $server) {}

    public function handle(): void
    {
        $connectedClients = Client::where('server_id', $this->server->id)->connected();

        $connectedClients->each(function (Client $client) {
            $foundNewServer = $client->user->assignServerToUser();
        });
    }
}
