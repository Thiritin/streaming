<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

class ClientPlayEvent
{
    use Dispatchable;

    public function __construct(public readonly int $client_id) {}
}
