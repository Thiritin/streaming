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

    public function __construct(public readonly Message $message, public readonly User $user)
    {
    }

    public function broadcastOn(): array
    {
        return [
            new Channel('chat')
        ];
    }

    public function broadcastWith(): array
    {
        return [
            "id" => $this->message->id,
            "name" => $this->user->name,
            "time" => $this->message->created_at->format('H:i'),
            "message" => $this->message->message,
            "level" => $this->user->level,
        ];
    }

    public function broadcastAs(): string
    {
        return 'message';
    }
}
