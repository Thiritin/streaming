<?php

namespace App\Jobs;

use App\Enum\StreamStatusEnum;
use App\Models\Client;
use App\Models\ServerUser;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

class CleanUpUnusedClientJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
    }

    public function handle(): void
    {
        // return false if stream is not online
        if (\Cache::get('stream.status', static fn() => StreamStatusEnum::OFFLINE->value) !== StreamStatusEnum::ONLINE->value) {
            return;
        }

        Client::whereNull('start')->where('created_at', '<', now()->subHours(4))->delete();
        ServerUser::whereNull('start')->whereDoesntHave('clients')->delete();
    }
}
