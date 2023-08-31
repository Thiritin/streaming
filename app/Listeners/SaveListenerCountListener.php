<?php

namespace App\Listeners;

use App\Events\StreamListenerChangeEvent;
use App\Services\StreamInfoService;

class SaveListenerCountListener
{
    public function __construct()
    {
    }

    public function handle(StreamListenerChangeEvent $event): void
    {
        StreamInfoService::setPreviousUserCount($event->listeners);
    }
}
