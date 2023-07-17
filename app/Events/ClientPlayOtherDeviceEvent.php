<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ClientPlayOtherDeviceEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly array $notifyClientIds)
    {
    }

    public function broadcastOn(): array
    {
        $channels = [];
        foreach ($this->notifyClientIds as $client_id) {
            $channels[] = new PrivateChannel('Client.'.$client_id);
        }
        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'otherDevice';
    }
}
