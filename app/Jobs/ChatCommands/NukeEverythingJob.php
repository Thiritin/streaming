<?php

namespace App\Jobs\ChatCommands;

use App\Events\Chat\Broadcasts\ChatSystemEvent;
use App\Models\Message;

class NukeEverythingJob extends AbstractChatCommand
{
    public static function getMeta(): array
    {
        return [
            'name' => 'nukeeverything_i_know_what_i_am_doing',
            'description' => 'Delete all chat messages (use with extreme caution)',
            'syntax' => '/nukeeverything_i_know_what_i_am_doing',
            'parameters' => [],
            'permission' => 'chat.commands.nukeall',
            'aliases' => [],
        ];
    }
    
    public function canExecute(): bool
    {
        return $this->user->can('chat.commands.nukeall');
    }
    
    protected function execute(): void
    {
        Message::all()->each(fn($message) => $message->delete());
        broadcast(new ChatSystemEvent('Welcome to the Eurofurence Stream Chat!'));
    }
}
