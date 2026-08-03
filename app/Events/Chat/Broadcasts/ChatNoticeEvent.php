<?php

namespace App\Events\Chat\Broadcasts;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
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

    /**
     * @param  bool  $modsOnly  Send on the moderator channel instead of the public one.
     *                          Punishments handed out to a single user are mod business:
     *                          the target is told privately over `user.{id}`, and the room
     *                          never sees a running log of who got hit.
     */
    public function __construct(
        public readonly string $text,
        public readonly ?int $sourceId = null,
        public readonly string $level = 'info',
        public readonly bool $modsOnly = false,
    ) {}

    public function broadcastOn(): array
    {
        return [
            $this->modsOnly
                ? new PrivateChannel('chat.source.'.$this->sourceId.'.mods')
                : new Channel('chat.source.'.$this->sourceId),
        ];
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
