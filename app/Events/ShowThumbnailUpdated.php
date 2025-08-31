<?php

namespace App\Events;

use App\Models\Show;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ShowThumbnailUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public Show $show;

    /**
     * Create a new event instance.
     */
    public function __construct(Show $show)
    {
        $this->show = $show;
    }

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): array
    {
        return [
            new Channel('shows'),
            new Channel('show.'.$this->show->id),
        ];
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'show_id' => $this->show->id,
            'thumbnail_url' => $this->show->thumbnail_url, // Uses accessor for signed URL
            'updated_at' => $this->show->thumbnail_updated_at?->toIso8601String(),
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'thumbnail.updated';
    }
}
