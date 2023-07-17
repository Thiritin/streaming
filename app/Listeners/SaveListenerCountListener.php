<?php

namespace App\Listeners;

use App\Events\StreamListenerChangeEvent;

class SaveListenerCountListener
{
    public function __construct()
    {
    }

    public function handle(StreamListenerChangeEvent $event): void
    {
        \Cache::set('stream.listeners', $event->listeners);
    }
}
