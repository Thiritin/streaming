<?php

namespace App\Jobs\Server;

use App\Enum\ServerStatusEnum;
use App\Enum\ServerTypeEnum;
use App\Models\Server;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ServerHealthCheckJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * Scheduled every minute, and it recomputes current state rather than
     * accumulating it - so a queued copy that has not run yet already does
     * everything a second one would.
     *
     * Without this the scheduler dispatches regardless of whether the last copy ever
     * ran. When Horizon's workers could not start (v0.4.0 to v0.4.3) that produced
     * 8,200 queued jobs in five hours, none of which were worth running by the time
     * they could be. `ShouldBeUnique` caps the standing backlog at one per class.
     *
     * `uniqueFor` is the lock's own expiry, a safety net for a job that dies without
     * releasing it; the lock is normally freed the moment the job finishes.
     */
    public int $uniqueFor = 300;

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

                if (! $healthy) {
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
