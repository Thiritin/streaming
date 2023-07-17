<?php

namespace App\Events;

use App\Models\Client;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ClientPlayEvent
{
    use Dispatchable;

    public function __construct(public readonly int $client_id)
    {

    }
}
