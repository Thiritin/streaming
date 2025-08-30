<?php

namespace App\Listeners\Chat\SlowMode;

use App\Events\Chat\Broadcasts\BroadcastRateLimitChangeEvent;
use App\Events\Chat\Commands\SlowModeDisabled;
use Illuminate\Support\Facades\Cache;

class SlowModeDisableListener
{
    public function __construct() {}

    public function handle(SlowModeDisabled $event): void
    {
        Cache::set('chat.maxTries', config('chat.default.maxTries'));
        Cache::set('chat.rateDecay', config('chat.default.rateDecay'));
        Cache::set('chat.slowMode', false);

        broadcast(new BroadcastRateLimitChangeEvent(
            slowMode: false,
            maxTries: config('chat.default.maxTries'),
            rateDecay: config('chat.default.rateDecay')
        ));
    }
}
