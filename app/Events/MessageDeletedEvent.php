<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageDeletedEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public array $messageIds;

    /**
     * Create a new event instance.
     *
     * @param string|array $messageIds
     */
    public function __construct($messageIds)
    {
        $this->messageIds = is_array($messageIds) ? $messageIds : [$messageIds];
    }

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): Channel
    {
        return new Channel('chat');
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'messagesDeleted';
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'ids' => $this->messageIds,
        ];
    }
}