<?php

namespace App\Jobs;

use App\Services\BoopCounter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Writes the boops that piled up in the cache back to `shows.boop_count` and
 * tells the room about them, once per tick rather than once per click.
 *
 * Unique, so a queue that stops draining does not stack up ticks that would then
 * all broadcast the same number.
 */
class FlushShowBoopsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $uniqueFor = 30;

    public function handle(BoopCounter $counter): void
    {
        $counter->flush();
    }
}
