<?php

namespace App\Events;

use App\Models\Source;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SourceStatusChangedEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public Source $source;
    public string $previousStatus;

    /**
     * Create a new event instance.
     */
    public function __construct(Source $source, string $previousStatus)
    {
        $this->source = $source;
        $this->previousStatus = $previousStatus;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new Channel('source.' . $this->source->id),
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs()
    {
        return 'source.status.changed';
    }

    /**
     * Get the data to broadcast.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'source_id' => $this->source->id,
            'status' => $this->source->status->value,
            'previous_status' => $this->previousStatus,
            'name' => $this->source->name,
            'slug' => $this->source->slug,
            'timestamp' => now()->toIso8601String(),
        ];
    }
}