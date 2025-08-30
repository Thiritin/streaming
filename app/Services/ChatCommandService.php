<?php

namespace App\Services;

use App\Jobs\ChatCommands\BroadcastJob;
use App\Jobs\ChatCommands\DeleteMessageJob;
use App\Jobs\ChatCommands\NukeEverythingJob;
use App\Jobs\ChatCommands\SlowModeJob;
use App\Jobs\ChatCommands\TimeoutJob;
use App\Models\User;

class ChatCommandService
{
    /**
     * Get all available commands for a user based on their permissions
     */
    public function getAvailableCommands(User $user): array
    {
        $commands = [];

        // Timeout command
        if ($user->can('chat.commands.timeout')) {
            $commands[] = TimeoutJob::getMeta();
        }

        // Slowmode command
        if ($user->can('chat.commands.slowmode')) {
            $commands[] = SlowModeJob::getMeta();
        }

        // Delete command
        if ($user->can('chat.commands.delete')) {
            $commands[] = DeleteMessageJob::getMeta();
        }

        // Broadcast command
        if ($user->can('chat.commands.broadcast')) {
            $commands[] = BroadcastJob::getMeta();
        }

        // Nuke everything command
        if ($user->can('chat.commands.nukeall')) {
            $commands[] = NukeEverythingJob::getMeta();
        }

        return $commands;
    }

    /**
     * Get command class by command name
     */
    public function getCommandClass(string $commandName): ?string
    {
        return match ($commandName) {
            'timeout' => TimeoutJob::class,
            'slowmode', 'slow' => SlowModeJob::class,
            'delete' => DeleteMessageJob::class,
            'broadcast' => BroadcastJob::class,
            'nukeeverything_i_know_what_i_am_doing' => NukeEverythingJob::class,
            default => null,
        };
    }

    /**
     * Check if a string is a command
     */
    public function isCommand(string $message): bool
    {
        $trimmed = trim($message);

        return str_starts_with($trimmed, '/') || str_starts_with($trimmed, '!');
    }

    /**
     * Extract command name from message
     */
    public function extractCommandName(string $message): string
    {
        $trimmed = ltrim(trim($message), '!/');
        $parts = explode(' ', $trimmed);

        return $parts[0] ?? '';
    }
}
