<?php

namespace App\Listeners\Chat\DeleteMessages;

use App\Events\Chat\Broadcasts\BroadcastMessageDeletionIdsEvent;
use App\Events\Chat\DeleteMessagesEvent;

class DeleteMessagesListener
{
    public function __construct()
    {
    }

    public function handle(DeleteMessagesEvent $event): void
    {
        $query = $event->user->messages()->where('is_command', false)->where('created_at', '>', $event->since);

        broadcast(new BroadcastMessageDeletionIdsEvent($query->pluck('id')->toArray()));

        $query->delete();
    }
}
