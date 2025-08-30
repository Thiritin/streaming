<?php

namespace App\Events\Chat\Commands;

use Illuminate\Foundation\Events\Dispatchable;

class SlowModeEnabled
{
    use Dispatchable;

    public function __construct(public int $seconds) {}
}
