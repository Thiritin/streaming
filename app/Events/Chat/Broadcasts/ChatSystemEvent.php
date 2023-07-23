<?php

namespace App\Events\Chat\Broadcasts;

use App\Models\Message;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ChatSystemEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    private Message $message;

    public function __construct(public string $text)
    {
        $this->message = Message::create([
            'user_id' => null,
            'message' => $text,
        ]);
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
            "name" => "System",
            "time" => $this->message->created_at->format('H:i'),
            "message" => $this->message->message,
            "level" => 99,
        ];
    }

    public function broadcastAs(): string
    {
        return 'message';
    }
}
