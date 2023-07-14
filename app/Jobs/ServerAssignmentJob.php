<?php

namespace App\Jobs;

use App\Events\ServerAssignedEvent;
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
        $users = User::where('is_provisioning',true)->get();
        $users->each(function($user) {
            $server = $user->assignServerToUser();
            // If server could be assigned, send broadcast to user
            if (!is_null($server)) {
                event(new ServerAssignedEvent($user));
            }
        });
    }
}
