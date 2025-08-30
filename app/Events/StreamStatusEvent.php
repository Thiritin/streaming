<?php

namespace App\Events;

use App\Enum\StreamStatusEnum;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class StreamStatusEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly StreamStatusEnum $status) {}

    public function broadcastOn(): array
    {
        return [
            new Channel('StreamInfo'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'stream.status.changed';
    }

    public function broadcastWith(): array
    {
        return ['status' => $this->status->value];
    }
}
