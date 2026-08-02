<?php

namespace App\Events\Chat\Broadcasts;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * An inline notice rendered in the chat log ("X was timed out", "slow mode on").
 * Not persisted: notices are ambient state, not history.
 */
class ChatNoticeEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly string $text,
        public readonly ?int $sourceId = null,
        public readonly string $level = 'info',
    ) {}

    public function broadcastOn(): array
    {
        return [new Channel('chat.source.'.$this->sourceId)];
    }

    public function broadcastWith(): array
    {
        return [
            'id' => 'notice_'.Str::random(12),
            'type' => 'notice',
            'level' => $this->level,
            'body' => $this->text,
            'time' => now()->format('H:i'),
            'timestamp' => now()->toIso8601String(),
            'source_id' => $this->sourceId,
        ];
    }

    public function broadcastAs(): string
    {
        return 'notice';
    }
}
