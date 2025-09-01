<?php

namespace App\Events;

use App\Models\Show;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ShowCancelled implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public Show $show;

    /**
     * Create a new event instance.
     */
    public function __construct(Show $show)
    {
        $this->show = $show->load('source');
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
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
     *
     * @return array
     */
    public function broadcastWith()
    {
        return [
            'id' => $this->show->id,
            'title' => $this->show->title,
            'slug' => $this->show->slug,
            'status' => $this->show->status,
            'source' => $this->show->source ? [
                'id' => $this->show->source->id,
                'name' => $this->show->source->name,
                'location' => $this->show->source->location,
                'status' => $this->show->source->status->value,
            ] : null,
            'scheduled_start' => $this->show->scheduled_start,
            'scheduled_end' => $this->show->scheduled_end,
        ];
    }

    /**
     * The event's broadcast name.
     *
     * @return string
     */
    public function broadcastAs()
    {
        return 'show.cancelled';
    }
}