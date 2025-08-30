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
        // Only get stream URLs if user has server assignment
        if ($this->user->server_id && $this->user->streamkey) {
            $data = $this->user->getUserStreamUrls();
            return [
                'hlsUrls' => $data['hls_urls'] ?? null,
                'clientId' => $data['client_id'],
                'provisioning' => false,
                'hasAssignment' => true,
            ];
        }
        
        // User is waiting for provisioning
        return [
            'hlsUrls' => null,
            'clientId' => null,
            'provisioning' => $this->provisioning,
            'hasAssignment' => false,
        ];
    }

    public function broadcastAs(): string
    {
        return 'server.assignment.changed';
    }
}
