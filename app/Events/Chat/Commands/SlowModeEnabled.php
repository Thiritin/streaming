<?php

namespace App\Events\Chat\Commands;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

class SlowModeEnabled
{
    use Dispatchable;

    public function __construct(public int $seconds)
    {
    }
}
