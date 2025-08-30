<?php

namespace App\Events\Chat\Broadcasts;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BroadcastRateLimitChangeEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public bool $slowMode,
        public int $maxTries,
        public int $rateDecay

    ) {}

    public function broadcastOn(): array
    {
        return [
            new Channel('chat'),
        ];
    }

    public function broadcastWith(): array
    {
        return [
            'maxTries' => $this->maxTries,
            'rateDecay' => $this->rateDecay,
            'slowMode' => $this->slowMode,
        ];
    }

    public function broadcastAs(): string
    {
        return 'rateLimit';
    }
}
