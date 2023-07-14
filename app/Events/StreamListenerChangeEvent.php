<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class StreamListenerChangeEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly int $listeners)
    {
    }

    public function broadcastOn(): array
    {
        return [
            new Channel('StreamInfo')
        ];
    }

    public function broadcastAs(): string
    {
        return 'stream.listeners.changed';
    }

    public function broadcastWith()
    {
        return ['listeners' => $this->listeners];
    }
}
