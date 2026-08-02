<?php

namespace App\Events\Chat\Broadcasts;

use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Tells a single user that their own chat privileges changed, so their client can
 * lock or unlock the composer without a page reload.
 */
class ChatUserStateEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly User $user,
        public readonly string $state, // timed_out | banned | cleared
        public readonly ?string $reason = null,
        public readonly ?int $secondsRemaining = null,
        public readonly ?int $sourceId = null,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('user.'.$this->user->id)];
    }

    public function broadcastWith(): array
    {
        return [
            'state' => $this->state,
            'reason' => $this->reason,
            'seconds_remaining' => $this->secondsRemaining,
            'source_id' => $this->sourceId,
        ];
    }

    public function broadcastAs(): string
    {
        return 'chat.state';
    }
}
