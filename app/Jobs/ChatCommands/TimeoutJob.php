<?php

namespace App\Jobs\ChatCommands;

use App\Models\User;
use Illuminate\Support\Carbon;

class TimeoutJob extends AbstractChatCommand
{
    public static function getMeta(): array
    {
        return [
            'name' => 'timeout',
            'description' => 'Timeout a user from chatting',
            'syntax' => '/timeout "username" "duration"',
            'parameters' => [
                ['name' => 'username', 'description' => 'The user to timeout', 'required' => true],
                ['name' => 'duration', 'description' => 'Duration (e.g., 5s, 5m, 5h)', 'required' => true],
            ],
            'permission' => 'chat.commands.timeout',
            'aliases' => [],
        ];
    }

    public function canExecute(): bool
    {
        return $this->user->can('chat.commands.timeout');
    }

    protected function execute(): void
    {
        $args = $this->parseArguments();

        if (count($args) < 2) {
            return;
        }

        $username = $args[0];
        $duration = $args[1];

        // Convert duration to Carbon
        try {
            $until = Carbon::now()->add($duration);
        } catch (\Exception $e) {
            return;
        }

        $targetUser = User::where('name', $username)->first();
        if ($targetUser === null) {
            return;
        }

        $targetUser->update([
            'timeout_expires_at' => $until,
        ]);
    }
}
