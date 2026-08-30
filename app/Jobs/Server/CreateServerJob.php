<?php

namespace App\Jobs\Server;

use App\Enum\ServerStatusEnum;
use App\Enum\ServerTypeEnum;
use App\Jobs\Server\Provision\CreateVirtualMachineJob;
use App\Models\Server;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CreateServerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct() {}

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
                self::dispatch()->delay(now()->addMinutes(1));

                return;
            }
        }

        $server = Server::create([
            'type' => $type,
            'status' => ServerStatusEnum::PROVISIONING,
        ]);

        // CreateVirtualMachineJob owns the rest of the chain. Chaining the DNS and
        // readiness jobs here as well ran both twice per provision.
        CreateVirtualMachineJob::dispatch($server);
    }
}
