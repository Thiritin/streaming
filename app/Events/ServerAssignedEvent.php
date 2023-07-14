<?php

namespace App\Events;

use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ServerAssignedEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly User $user)
    {
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('User.'.$this->user->id.'.StreamUrl')
        ];
    }

    public function broadcastWith()
    {
        return ['streamUrls' => $this->user->getUserStreamUrls()];
    }

    public function broadcastAs(): string
    {
        return 'stream.url.changed';
    }
}
