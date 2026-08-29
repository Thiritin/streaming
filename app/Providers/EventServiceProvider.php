<?php

namespace App\Providers;

use App\Events\SourceStatusChangedEvent;
use App\Events\StreamListenerChangeEvent;
use App\Events\StreamStatusEvent;
use App\Listeners\AssignBaselineRole;
use App\Listeners\HandleAutoModeShowsListener;
use App\Listeners\SaveListenerCountListener;
use App\Listeners\SetCacheStatusListener;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Events\Verified;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event to listener mappings for the application.
     *
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        Registered::class => [
            SendEmailVerificationNotification::class,
        ],
        Verified::class => [
            AssignBaselineRole::class,
        ],
        StreamStatusEvent::class => [
            SetCacheStatusListener::class,
        ],
        StreamListenerChangeEvent::class => [
            SaveListenerCountListener::class,
        ],
        SourceStatusChangedEvent::class => [
            HandleAutoModeShowsListener::class,
        ],
    ];

    /**
     * Register any events for your application.
     */
    public function boot(): void
    {
        //
    }

    /**
     * Determine if events and listeners should be automatically discovered.
     */
    public function shouldDiscoverEvents(): bool
    {
        return false;
    }
}
