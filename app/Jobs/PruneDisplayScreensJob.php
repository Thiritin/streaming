<?php

namespace App\Jobs;

use App\Models\DisplayScreen;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * A row per screen that has ever presented a key, and nothing else removes them.
 *
 * The list only ever shows screens that are polling, so a row that has been quiet
 * for a day describes a screen that was unplugged or reimaged. Kept a day rather
 * than an hour so "what was Hall 2 on yesterday" is still answerable during a
 * convention.
 */
class PruneDisplayScreensJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 3600;

    public function handle(): void
    {
        $deleted = DisplayScreen::where('last_seen_at', '<', now()->subDay())
            ->orWhereNull('last_seen_at')
            ->delete();

        if ($deleted > 0) {
            Log::info('Pruned display screens', ['deleted' => $deleted]);
        }
    }
}
