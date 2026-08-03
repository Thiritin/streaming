<?php

namespace App\Events\Chat\Broadcasts;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ChatSettingsUpdatedEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array<string, mixed>  $settings
     */
    public function __construct(
        public readonly array $settings,
        public readonly ?int $sourceId = null,
    ) {}

    public function broadcastOn(): array
    {
        return [new Channel('chat.source.'.$this->sourceId)];
    }

    public function broadcastWith(): array
    {
        return [
            'settings' => $this->settings,
            'source_id' => $this->sourceId,
        ];
    }

    public function broadcastAs(): string
    {
        return 'settings.updated';
    }
}
