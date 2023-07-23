<?php

namespace App\Listeners\Chat\SlowMode;

use App\Events\Chat\Commands\SlowModeEnabled;

class AnnounceSlowModeListener
{
    public function __construct()
    {
    }

    public function handle(SlowModeEnabled $event): void
    {
        broadcast(new \App\Events\Chat\Broadcasts\ChatSystemEvent("Slow mode has been activated, you can write a message every {$event->seconds} seconds."));
    }
}
