<?php

namespace App\Jobs;

use App\Models\ViewCount;
use App\Services\StreamInfoService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SaveViewCountJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

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

    public function __construct() {}

    public function handle(): void
    {
        ViewCount::create([
            'count' => StreamInfoService::getUserCount(),
        ]);
    }
}
