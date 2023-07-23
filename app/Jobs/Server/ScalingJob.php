<?php

namespace App\Jobs\Server;

use App\Enum\ServerStatusEnum;
use App\Enum\ServerTypeEnum;
use App\Enum\StreamStatusEnum;
use App\Models\Server;
use App\Models\ServerUser;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use LKDev\HetznerCloud\Models\Servers\Types\ServerType;

class ScalingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct()
    {
    }

    public function handle(): void
    {
        $cacheStatus = StreamStatusEnum::tryFrom(Cache::get('stream.status', static fn() => StreamStatusEnum::OFFLINE->value));

        if ($cacheStatus !== StreamStatusEnum::OFFLINE) {
            // Total active users in stream
            $serverUserCount = ServerUser::whereNotNull('stop')->whereHas('clients', fn($q) => $q->whereNotNull('start')->whereNull('stop'))->count();
            // Capactiy of servers that are in provisioning and active max_clients
            $serverCapacity = Server::whereIn('status', [ServerStatusEnum::PROVISIONING->value, ServerStatusEnum::ACTIVE->value])
                ->where('type',ServerTypeEnum::EDGE->value)
                ->sum('max_clients');
            // Is capacity over 80%
            $isOverCapacity = $serverUserCount > ($serverCapacity * 0.8);
            // Is under capacity 20%
            $isUnderCapacity = $serverUserCount < ($serverCapacity * 0.2);

            if ($isOverCapacity) {
                CreateServerJob::dispatch();
            }

            if ($isUnderCapacity) {
                $serverCount = Server::whereIn('status', [ServerStatusEnum::ACTIVE->value])->where('type', ServerTypeEnum::EDGE)->count();

                if ($serverCount > 1) {
                    // Delete Server with lowest user count and not immutable
                    $server = Server::where('status', ServerStatusEnum::ACTIVE)
                        ->where('type', ServerTypeEnum::EDGE)
                        ->where('immutable', false)
                        ->leftJoin('server_user', function (JoinClause $join) {
                            $join->on('server_user.server_id', '=', 'servers.id');
                            $join->on('server_user.stop', \DB::raw('NULL'));
                        })
                        ->groupBy('servers.id')
                        ->orderBy('client_counts', 'desc')
                        ->selectRaw("servers.id, count(server_user.id) as client_counts")
                        ->first();

                    if (!is_null($server)) {
                        $server = Server::find($server->id);
                        if($server->immutable) {
                            return;
                        }
                        DeleteServerJob::dispatch($server);
                    }
                }
            }
        }
    }
}
