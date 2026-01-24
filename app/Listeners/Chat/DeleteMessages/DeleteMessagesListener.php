<?php

namespace App\Listeners\Chat\DeleteMessages;

use App\Events\Chat\Broadcasts\BroadcastMessageDeletionIdsEvent;
use App\Events\Chat\DeleteMessagesEvent;

class DeleteMessagesListener
{
    public function __construct() {}

    public function handle(DeleteMessagesEvent $event): void
    {
        $messages = $event->user->messages()
            ->where('is_command', false)
            ->where('created_at', '>', $event->since)
            ->get(['id', 'source_id']);

        // Group messages by source_id for broadcasting
        $messagesBySource = $messages->groupBy('source_id');

        // Broadcast deletion event to each source channel
        foreach ($messagesBySource as $sourceId => $sourceMessages) {
            broadcast(new BroadcastMessageDeletionIdsEvent(
                $sourceMessages->pluck('id')->toArray(),
                $sourceId
            ));
        }

        // Delete the messages
        $event->user->messages()
            ->where('is_command', false)
            ->where('created_at', '>', $event->since)
            ->delete();
    }
}
