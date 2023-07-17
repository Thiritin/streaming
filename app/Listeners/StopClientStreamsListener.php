<?php

namespace App\Listeners;

use App\Events\ClientPlayOtherDeviceEvent;
use App\Models\Client;
use Illuminate\Contracts\Queue\ShouldQueue;

class StopClientStreamsListener implements ShouldQueue
{
    public function __construct()
    {
    }

    public function handle(ClientPlayOtherDeviceEvent $event): void
    {
        foreach ($event->notifyClientIds as $client_id) {
            $client = Client::find($client_id);
            $client->disconnect();
        }
    }
}
