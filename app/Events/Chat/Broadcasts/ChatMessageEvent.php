<?php

namespace App\Events\Chat\Broadcasts;

use App\Models\Message;
use App\Services\Chat\MessagePresenter;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ChatMessageEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly Message $message) {}

    public function broadcastOn(): array
    {
        return [new Channel('chat.source.'.$this->message->source_id)];
    }

    public function broadcastWith(): array
    {
        return app(MessagePresenter::class)->present($this->message);
    }

    public function broadcastAs(): string
    {
        return 'message';
    }
}
