<?php

namespace App\Listeners;

use App\Events\ClientPlayEvent;
use App\Events\ClientPlayOtherDeviceEvent;
use App\Models\Client;
use Illuminate\Contracts\Queue\ShouldQueue;

class DispatchPaysOtherDeviceNotifcationListener implements ShouldQueue
{
    public function __construct()
    {
    }

    public function handle(ClientPlayEvent $event): void
    {
        $client = Client::with('serverUser')->where('id', $event->client_id)->firstOrFail();
        $ids = Client::where('server_user_id', $client->server_user_id)
            ->where('id', '!=', $client->id)
            ->whereNotNull('start')
            ->whereNull('stop')
            ->pluck('id')
            ->toArray();
        ClientPlayOtherDeviceEvent::dispatch($ids);
    }
}
