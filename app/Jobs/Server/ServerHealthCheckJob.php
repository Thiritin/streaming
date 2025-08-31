<?php

namespace App\Jobs\Server;

use App\Models\Server;
use App\Enum\ServerTypeEnum;
use App\Enum\ServerStatusEnum;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ServerHealthCheckJob implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Get all active edge servers
        $edgeServers = Server::where('type', ServerTypeEnum::EDGE)
            ->where('status', ServerStatusEnum::ACTIVE)
            ->get();

        foreach ($edgeServers as $server) {
            try {
                $healthy = $server->performHealthCheck();
                
                if (!$healthy) {
                    Log::warning('Server health check failed', [
                        'server_id' => $server->id,
                        'hostname' => $server->hostname,
                        'message' => $server->health_check_message,
                    ]);
                }
            } catch (\Exception $e) {
                Log::error('Server health check error', [
                    'server_id' => $server->id,
                    'hostname' => $server->hostname,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
