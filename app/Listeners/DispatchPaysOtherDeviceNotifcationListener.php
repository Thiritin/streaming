<?php

namespace App\Listeners;

use App\Events\ClientPlayEvent;
use App\Events\ClientPlayOtherDeviceEvent;
use App\Models\Client;
use Illuminate\Contracts\Queue\ShouldQueue;

class DispatchPaysOtherDeviceNotifcationListener implements ShouldQueue
{
    public function __construct() {}

    public function handle(ClientPlayEvent $event): void
    {
        $client = Client::where('id', $event->client_id)->firstOrFail();
        $ids = Client::where('id', '!=', $client->id)
            ->where('user_id', $client->user_id)
            ->connected()
            ->pluck('id')
            ->toArray();
        ClientPlayOtherDeviceEvent::dispatch($ids);
    }
}
