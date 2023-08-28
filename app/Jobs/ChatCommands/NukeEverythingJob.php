<?php

namespace App\Jobs\ChatCommands;

use App\Events\Chat\Broadcasts\ChatSystemEvent;
use App\Events\Chat\Commands\SlowModeDisabled;
use App\Events\Chat\Commands\SlowModeEnabled;
use App\Models\Message;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class NukeEverythingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly User $user, public readonly Message $message, public readonly string $command)
    {
    }

    public function handle(): void
    {
        if($this->user->cannot('chat.commands.nukeall')) {
            return;
        }
        Message::all()->each(fn($message) => $message->delete());
        broadcast(new ChatSystemEvent('Welcome to the Eurofurence Stream Chat!'));
    }
}
