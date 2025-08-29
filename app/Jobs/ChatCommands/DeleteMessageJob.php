<?php

namespace App\Jobs\ChatCommands;

use App\Events\Chat\DeleteMessagesEvent;
use App\Models\User;
use Illuminate\Support\Carbon;

class DeleteMessageJob extends AbstractChatCommand
{
    public static function getMeta(): array
    {
        return [
            'name' => 'delete',
            'description' => 'Delete messages from a user',
            'syntax' => '/delete "username" "timespan"',
            'parameters' => [
                ['name' => 'username', 'description' => 'The user whose messages to delete', 'required' => true],
                ['name' => 'timespan', 'description' => 'How far back to delete (e.g., 5s, 5m, 5h)', 'required' => true],
            ],
            'permission' => 'chat.commands.delete',
            'aliases' => [],
        ];
    }
    
    public function canExecute(): bool
    {
        return $this->user->can('chat.commands.delete');
    }
    
    protected function execute(): void
    {
        $args = $this->parseArguments();
        
        if (count($args) < 2) {
            return;
        }
        
        $username = $args[0];
        $timespan = $args[1];
        
        // Convert timespan to Carbon
        try {
            $since = Carbon::now()->sub($timespan);
        } catch (\Exception $e) {
            return;
        }
        
        $targetUser = User::where('name', $username)->first();
        if ($targetUser === null) {
            return;
        }
        
        broadcast(new DeleteMessagesEvent($targetUser, $since));
    }
}
