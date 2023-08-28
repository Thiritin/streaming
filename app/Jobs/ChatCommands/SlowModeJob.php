<?php

namespace App\Jobs\ChatCommands;

use App\Events\Chat\Commands\SlowModeDisabled;
use App\Events\Chat\Commands\SlowModeEnabled;
use App\Models\Message;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SlowModeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly User $user, public readonly Message $message, public readonly string $command)
    {
    }

    public function handle(): void
    {
        if($this->user->cannot('chat.commands.slowmode')) {
            return;
        }
        preg_match('/^!(slow|slowmode)\s+"?([^"]+)"?$/', $this->command, $matches);

        if(!isset($matches[2])) return;

        if($matches[2] === 'off' || $matches[2] === '0' || $matches[2] === 0) {
            event(new SlowModeDisabled());
            return;
        }

        if(is_numeric($matches[2]) && $matches[2] < 500) {
            event(new SlowModeEnabled($matches[2]));
        }


    }
}
