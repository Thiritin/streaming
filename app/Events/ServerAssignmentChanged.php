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

    public function __construct(public readonly User $user, public bool $provisioning = false) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('User.'.$this->user->id.'.StreamUrl'),
        ];
    }

    /**
     * Announces only whether an edge has been assigned yet.
     *
     * It used to carry an `hlsUrls` map built by `User::getUserStreamUrls()`. Those
     * URLs could never have worked: the stream name was hardcoded to `livestream`
     * rather than a source slug, and four of the seven qualities listed (`ld`,
     * `audio_hd`, `audio_sd`, and a bare `original`) have not existed since SRS
     * stopped transcoding. Nothing subscribed to them either. Playback URLs come
     * from the `hls.*` routes, which resolve the viewer's edge per request.
     */
    public function broadcastWith()
    {
        $assigned = (bool) ($this->user->server_id && $this->user->streamkey);

        return [
            'provisioning' => $assigned ? false : $this->provisioning,
            'hasAssignment' => $assigned,
        ];
    }

    public function broadcastAs(): string
    {
        return 'server.assignment.changed';
    }
}
