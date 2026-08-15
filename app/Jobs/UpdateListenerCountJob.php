<?php

namespace App\Jobs;

use App\Events\StreamListenerChangeEvent;
use App\Services\StreamInfoService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class UpdateListenerCountJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct() {}

    public function handle(): void
    {
        event(new StreamListenerChangeEvent(StreamInfoService::getUserCount()));
    }
}
