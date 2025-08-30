<?php

namespace App\Listeners\Chat\SlowMode;

use App\Events\Chat\Broadcasts\BroadcastRateLimitChangeEvent;
use App\Events\Chat\Commands\SlowModeEnabled;
use Illuminate\Support\Facades\Cache;

class SlowModeEnableListener
{
    public function __construct() {}

    public function handle(SlowModeEnabled $event): void
    {
        Cache::set('chat.maxTries', 1);
        Cache::set('chat.rateDecay', $event->seconds);
        Cache::set('chat.slowMode', true);

        broadcast(new BroadcastRateLimitChangeEvent(
            slowMode: true,
            maxTries: 1,
            rateDecay: $event->seconds
        ));
    }
}
