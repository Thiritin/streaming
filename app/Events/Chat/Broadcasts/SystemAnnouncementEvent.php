<?php

namespace App\Events\Chat\Broadcasts;

use App\Models\Message;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SystemAnnouncementEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly Message $message) {}

    public function broadcastOn(): array
    {
        return [
            new Channel('chat'),
        ];
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->message->id,
            'name' => 'System Announcement',
            'time' => $this->message->created_at->format('H:i'),
            'message' => $this->message->message,
            'role' => (object) [
                'name' => 'System',
                'slug' => 'system',
                'chat_color' => '#FFD700'
            ],
            'chat_color' => '#FFD700',
            'type' => $this->message->type,
            'priority' => $this->message->priority,
            'metadata' => $this->message->metadata,
        ];
    }

    public function broadcastAs(): string
    {
        return 'message';
    }
}