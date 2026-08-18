<?php

namespace App\Jobs\Server\Provision;

use App\Enum\ServerStatusEnum;
use App\Enum\ServerTypeEnum;
use App\Models\Server;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

class SetServerAvailableJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public readonly Server $server) {}

    public function handle(): void
    {
        $server = $this->server;
        $server->update([
            'status' => ServerStatusEnum::ACTIVE,
        ]);

        // A new edge is invisible until the cached list is rebuilt, so drop it rather
        // than making viewers wait out the TTL. Nothing else is needed: viewers are
        // placed on the playlist request, so the next one to arrive can land here.
        if ($this->server->type === ServerTypeEnum::EDGE) {
            Cache::forget('hls_active_edges');
        }
    }
}
