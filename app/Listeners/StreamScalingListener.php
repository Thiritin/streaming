<?php

namespace App\Listeners;

use App\Enum\ServerStatusEnum;
use App\Enum\ServerTypeEnum;
use App\Enum\StreamStatusEnum;
use App\Events\StreamStatusEvent;
use App\Jobs\Server\CreateServerJob;
use App\Jobs\Server\Deprovision\DeleteDnsRecordJob;
use App\Jobs\Server\Deprovision\DeleteVirtualMachineJob;
use App\Jobs\Server\Deprovision\InitializeDeprovisioningJob;
use App\Jobs\Server\Deprovision\ServerMoveClientsToOtherServerJob;
use App\Models\Server;
use Illuminate\Support\Facades\Bus;

class StreamScalingListener
{
    public int $tries = 1;

    public function __construct() {}

    public function handle(StreamStatusEvent $event): void
    {
        if ($event->status === StreamStatusEnum::OFFLINE) {
            Server::whereIn('status', [ServerStatusEnum::PROVISIONING->value, ServerStatusEnum::ACTIVE])->each(function (Server $server) {
                Bus::chain([
                    new InitializeDeprovisioningJob($server),
                    new ServerMoveClientsToOtherServerJob($server),
                    new DeleteDnsRecordJob($server),
                    new DeleteVirtualMachineJob($server),
                ])->dispatch();
            });
        }

        if ($event->status === StreamStatusEnum::STARTING_SOON) {
            if (Server::where('type', ServerTypeEnum::ORIGIN)->whereIn('status', [
                ServerStatusEnum::ACTIVE->value,
                ServerStatusEnum::PROVISIONING->value,
            ])->doesntExist()) {
                CreateServerJob::dispatch();

                if (Server::where('type', ServerTypeEnum::EDGE)->whereIn('status', [
                    ServerStatusEnum::ACTIVE->value,
                    ServerStatusEnum::PROVISIONING->value,
                ])->doesntExist()) {
                    CreateServerJob::dispatch()->delay(now()->addMinute());
                }
            }
        }
    }
}
