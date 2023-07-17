<?php

namespace App\Jobs\Server\Deprovision;

use App\Events\ServerAssignmentChanged;
use App\Models\Server;
use App\Models\ServerUser;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;

class ServerMoveClientsToOtherServerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(private readonly Server $server)
    {

    }

    public function handle(): void
    {

        $serverUser = $this->server->user()->whereNull('stop')->get();

        if ($serverUser->count() === 0) {
            Bus::chain([
                new InitializeDeprovisioningJob($this->server),
                (new ServerMoveClientsToOtherServerJob($this->server))->delay(now()->addMinutes(5)),
                new DeleteDnsRecordJob($this->server),
                new DeleteVirtualMachineJob($this->server)
            ])->dispatch();
        }

        ServerUser::whereIn('user_id', $serverUser->pluck('id'))
            ->where('server_id', $this->server->id)
            ->update(['stop' => now()]);

        $serverUser->each(function (User $user) {
            $foundNewServer = $user->assignServerToUser();
            ServerAssignmentChanged::dispatch($user, !$foundNewServer);
        });
    }
}
