<?php

namespace App\Events;

use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ServerAssignmentChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly User $user, public bool $provisioning = false)
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
        return [
            'streamUrls' => $this->user->getUserStreamUrls()['urls'],
            'clientId' => $this->user->getUserStreamUrls()['client_id'],
            'provisioning' => $this->provisioning,
        ];
    }

    public function broadcastAs(): string
    {
        return 'server.assignment.changed';
    }
}
