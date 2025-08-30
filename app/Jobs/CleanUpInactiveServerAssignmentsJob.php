<?php

namespace App\Jobs;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CleanUpInactiveServerAssignmentsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct() {}

    public function handle(): void
    {
        // Get list of Users that connection ended more than 5 minutes ago
        $users = User::whereNotNull('server_id')
            ->whereDoesntHave('clients', fn ($q) => $q->where(fn ($q) => $q->connected())
                ->orWhere('stop', '>', now()->subMinutes(5)))
            ->get()
            ->each(function (User $user) {
                $user->server_id = null;
                $user->save();
            });

    }
}
