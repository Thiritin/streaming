<?php

namespace App\Events\Chat\Broadcasts;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ChatMessagesDeletedEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array<int, int>  $ids  Deleted message ids
     */
    public function __construct(
        public readonly array $ids,
        public readonly ?int $sourceId = null,
        public readonly ?string $targetName = null,
        public readonly ?string $moderatorName = null,
    ) {}

    public function broadcastOn(): array
    {
        return [new Channel('chat.source.'.$this->sourceId)];
    }

    public function broadcastWith(): array
    {
        return [
            'ids' => $this->ids,
            'source_id' => $this->sourceId,
            'target_name' => $this->targetName,
            'moderator_name' => $this->moderatorName,
        ];
    }

    public function broadcastAs(): string
    {
        return 'messages.deleted';
    }
}
