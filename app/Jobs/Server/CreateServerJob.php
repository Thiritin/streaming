<?php

namespace App\Jobs\Server;

use App\Enum\ServerStatusEnum;
use App\Enum\ServerTypeEnum;
use App\Jobs\Server\Provision\CreateDnsRecordJob;
use App\Jobs\Server\Provision\CreateVirtualMachineJob;
use App\Jobs\Server\Provision\WaitUntilServerIsReadyJob;
use App\Models\Server;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;

class CreateServerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct()
    {
    }

    public function handle(): void
    {
        $type = ServerTypeEnum::ORIGIN;
        if (Server::where('type', ServerTypeEnum::ORIGIN)->whereIn('status', [ServerStatusEnum::ACTIVE->value, ServerStatusEnum::PROVISIONING->value])->exists()) {
            $type = ServerTypeEnum::EDGE;
        } else {
            $type = ServerTypeEnum::ORIGIN;
        }

        if ($type === ServerTypeEnum::EDGE) {
            if (Server::where('type', ServerTypeEnum::ORIGIN)->whereIn('status', [ServerStatusEnum::PROVISIONING->value])->exists()) {
                self::dispatch($type)->delay(now()->addMinutes(1));
                return;
            }
        }

        $server = Server::create([
            'type' => $type,
            'shared_secret' => Str::random(32),
            'status' => ServerStatusEnum::PROVISIONING,
        ]);

        Bus::chain([
            new CreateVirtualMachineJob($server),
            new CreateDnsRecordJob($server),
            new WaitUntilServerIsReadyJob($server),
        ])->dispatch();
    }


}
