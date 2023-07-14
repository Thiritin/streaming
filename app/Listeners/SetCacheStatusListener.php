<?php

namespace App\Listeners;

use App\Events\StreamStatusEvent;
use Illuminate\Support\Facades\Cache;

class SetCacheStatusListener
{
    public function __construct()
    {
    }

    public function handle(StreamStatusEvent $event): void
    {
        Cache::put('stream.status', $event->status->value);
    }
}
