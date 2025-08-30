<?php

namespace App\Jobs;

use App\Events\ServerAssignmentChanged;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ServerAssignmentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
    }

    public function handle(): void
    {
        // Get all users waiting for provisioning
        $users = User::where('is_provisioning', true)->get();
        
        $users->each(function($user) {
            $assigned = $user->assignServerToUser();
            
            if ($assigned) {
                // Server assignment will trigger UserObserver which broadcasts ServerAssignmentChanged
                \Log::info("Assigned server to user {$user->id} from provisioning queue");
            }
        });
    }
}
