<?php

namespace App\Jobs;

use App\Events\ServerAssignmentChanged;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ServerAssignmentJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Scheduled every 15 seconds, and it recomputes current state rather than
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

    public function __construct() {}

    public function handle(): void
    {
        // Get all users without server assignment
        $users = User::whereNull('server_id')->get();

        $users->each(function ($user) {
            $assigned = $user->assignServerToUser();

            if ($assigned) {
                // Server assignment will trigger UserObserver which broadcasts ServerAssignmentChanged
                \Log::info("Assigned server to user {$user->id} from provisioning queue");
            }
        });
    }
}
