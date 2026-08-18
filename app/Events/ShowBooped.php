<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A batch of boops on a show.
 *
 * `delta` is what piled up since the last broadcast, not one click: the client
 * batches its clicks and BoopController broadcasts at most once a second per
 * show, so a busy room stays at one message per second instead of thousands.
 */
class ShowBooped implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly int $showId,
        public readonly int $total,
        public readonly int $delta,
    ) {}

    public function broadcastOn(): array
    {
        return [new Channel('show.'.$this->showId)];
    }

    public function broadcastAs(): string
    {
        return 'show.booped';
    }

    public function broadcastWith(): array
    {
        return [
            'show_id' => $this->showId,
            'total' => $this->total,
            'delta' => $this->delta,
        ];
    }
}
