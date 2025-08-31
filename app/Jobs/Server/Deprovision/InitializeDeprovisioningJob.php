<?php

namespace App\Jobs\Server\Deprovision;

use App\Enum\ServerStatusEnum;
use App\Models\Server;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class InitializeDeprovisioningJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public readonly Server $server) {}

    public function handle(): void
    {
        // Get all users currently assigned to this server
        $usersToReassign = $this->server->users()->get();
        $userCount = $usersToReassign->count();
        
        if ($userCount > 0) {
            Log::info('Reassigning users from deprovisioning server', [
                'server_id' => $this->server->id,
                'server_hostname' => $this->server->hostname,
                'user_count' => $userCount,
            ]);
            
            $reassignedCount = 0;
            $failedCount = 0;
            
            // Attempt to reassign each user to another available server
            foreach ($usersToReassign as $user) {
                // The assignServerToUser method will find the best available server
                // and assign the user to it, or clear the assignment if no servers are available
                if ($user->assignServerToUser()) {
                    $reassignedCount++;
                    Log::info('User reassigned to new server', [
                        'user_id' => $user->id,
                        'old_server_id' => $this->server->id,
                        'new_server_id' => $user->fresh()->server_id,
                    ]);
                } else {
                    $failedCount++;
                    Log::warning('Failed to reassign user - no available servers', [
                        'user_id' => $user->id,
                        'old_server_id' => $this->server->id,
                    ]);
                }
            }
            
            Log::info('User reassignment complete', [
                'server_id' => $this->server->id,
                'total_users' => $userCount,
                'reassigned' => $reassignedCount,
                'failed' => $failedCount,
            ]);
        }
        
        // Update server status to deprovisioning
        $this->server->update([
            'status' => ServerStatusEnum::DEPROVISIONING,
        ]);
    }
}
