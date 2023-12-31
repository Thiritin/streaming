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
use Illuminate\Support\Facades\Cache;

class TimeoutJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly User $user, public readonly Message $message, public readonly string $command)
    {
    }

    public function handle(): void
    {
        if($this->user->cannot('chat.commands.timeout')) {
            return;
        }

        preg_match('/^!(timeout)\s+"?([^"]+)"? "?([^"]+)"?$/', $this->command, $matches);

        if(!isset($matches[2], $matches[3])) return;

        // Match 3 contains e.x. 5 or 5s or 5m or 5h convert to carbon
        try {
            $until = Carbon::now()->add($matches[3]);
        } catch (\Exception $e) {
            return;
        }

        $user = User::where('name', $matches[2])->first();
        if($user === null) return;

        $user->update([
            'timeout_expires_at' => $until
        ]);
    }
}
