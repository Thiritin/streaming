<?php

namespace App\Jobs\ChatCommands;

use App\Events\Chat\Broadcasts\ChatSystemEvent;
use App\Models\Message;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class BroadcastJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly User $user, public readonly Message $message,public readonly string $command)
    {
    }

    public function handle(): void
    {
        preg_match('/^!broadcast\s+"?([^"]+)"?$/', $this->command, $matches);

        if(!isset($matches[1])) return;

        broadcast(new ChatSystemEvent($matches[1]));
    }
}
