<?php

namespace App\Jobs\ChatCommands;

use App\Events\Chat\DeleteMessagesEvent;
use App\Models\Message;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

class DeleteMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly User $user, public readonly Message $message, public readonly string $command)
    {
    }

    public function handle(): void
    {
        if($this->user->cannot('chat.commands.delete')) {
            return;
        }
        preg_match('/^!(delete)\s+"?([^"]+)"? "?([^"]+)"?$/', $this->command, $matches);

        if(!isset($matches[2], $matches[3])) return;

        // Match 3 contains e.x. 5 or 5s or 5m or 5h convert to carbon
        try {
            $since = Carbon::now()->sub($matches[3]);
        } catch (\Exception $e) {
            return;
        }

        $user = User::where('name', $matches[2])->first();
        if($user === null) return;

        broadcast(new DeleteMessagesEvent($user, $since));
    }
}
