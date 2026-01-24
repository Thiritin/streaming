<?php

namespace App\Events\Chat\Broadcasts;

use App\Models\Message;
use App\Models\User;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ChatMessageEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly Message $message, public readonly User $user) {}

    public function broadcastOn(): array
    {
        return [
            new Channel('chat.source.'.$this->message->source_id),
        ];
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->message->id,
            'name' => $this->user->name,
            'time' => $this->message->created_at->format('H:i'),
            'message' => $this->message->message,
            'role' => $this->user->role,
            'chat_color' => $this->user->chat_color,
            'type' => $this->message->type,
            'priority' => $this->message->priority,
            'metadata' => $this->message->metadata,
            'source_id' => $this->message->source_id,
        ];
    }

    public function broadcastAs(): string
    {
        return 'message';
    }
}
