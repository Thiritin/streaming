<?php

namespace App\Jobs\ChatCommands;

use App\Events\Chat\Broadcasts\ChatSystemEvent;

class BroadcastJob extends AbstractChatCommand
{
    public static function getMeta(): array
    {
        return [
            'name' => 'broadcast',
            'description' => 'Send a system message to all users',
            'syntax' => '/broadcast "message"',
            'parameters' => [
                ['name' => 'message', 'description' => 'The message to broadcast', 'required' => true],
            ],
            'permission' => 'chat.commands.broadcast',
            'aliases' => [],
        ];
    }

    public function canExecute(): bool
    {
        return $this->user->can('chat.commands.broadcast');
    }

    protected function execute(): void
    {
        $args = $this->parseArguments();

        if (count($args) < 1) {
            return;
        }

        // Join all arguments as the message (in case it contains spaces)
        $message = implode(' ', $args);

        broadcast(new ChatSystemEvent($message));
    }
}
