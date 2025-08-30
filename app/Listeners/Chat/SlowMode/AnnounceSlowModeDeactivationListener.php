<?php

namespace App\Listeners\Chat\SlowMode;

use App\Events\Chat\Broadcasts\ChatSystemEvent;
use App\Events\Chat\Commands\SlowModeDisabled;

class AnnounceSlowModeDeactivationListener
{
    public function __construct() {}

    public function handle(SlowModeDisabled $event): void
    {
        broadcast(new ChatSystemEvent('Slow mode has been disabled.'));
    }
}
