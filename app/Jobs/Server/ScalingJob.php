<?php

namespace App\Jobs\Server;

use App\Enum\AutoscalerAction;
use App\Enum\ServerStatusEnum;
use App\Enum\ServerTypeEnum;
use App\Enum\StreamStatusEnum;
use App\Models\Server;
use App\Services\AutoscalerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

class ScalingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct() {}

    public function handle(): void
    {
        $cacheStatus = StreamStatusEnum::tryFrom(Cache::get('stream.status',
            static fn () => StreamStatusEnum::OFFLINE->value));

        if (! AutoscalerService::isAutoscalerEnabled()) {
            return;
        }

        if ($cacheStatus === StreamStatusEnum::OFFLINE) {
            return;
        }

        // Determine Needed Servers
        $action = AutoscalerService::determineAction();

        if ($action === AutoscalerAction::SCALE_UP) {
            CreateServerJob::dispatch();
        }

        if ($action === AutoscalerAction::SCALE_DOWN) {
            $serverCount = Server::where('status', ServerStatusEnum::ACTIVE->value)
                ->where('type', ServerTypeEnum::EDGE)
                ->where('hetzner_id', '!=', 'manual')  // Don't count manual servers
                ->count();

            if ($serverCount > 1) {
                // Delete Server with lowest user count and not immutable or manual
                $server = Server::where('status', ServerStatusEnum::ACTIVE)
                    ->where('type', ServerTypeEnum::EDGE)
                    ->where('servers.created_at', '<=', now()->subHour())
                    ->where('immutable', false)
                    ->where('hetzner_id', '!=', 'manual')  // Never deprovision manual servers
                    ->leftJoin('clients', function (JoinClause $join) {
                        $join->on('clients.server_id', '=', 'servers.id');
                        $join->on('clients.stop', \DB::raw('NULL'));
                        $join->on('clients.start', 'IS NOT', \DB::raw('NULL'));
                    })
                    ->groupBy('servers.id')
                    ->orderBy('client_counts', 'desc')
                    ->selectRaw('servers.id, count(clients.id) as client_counts')
                    ->first();

                if (! is_null($server)) {
                    $server = Server::find($server->id);
                    if ($server->immutable) {
                        return;
                    }
                    DeleteServerJob::dispatch($server);
                }
            }
        }
    }
}
