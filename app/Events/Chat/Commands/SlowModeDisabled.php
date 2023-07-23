<?php

namespace App\Events\Chat\Commands;

use Illuminate\Foundation\Events\Dispatchable;

class SlowModeDisabled
{
    use Dispatchable;

    public function __construct()
    {
    }
}
