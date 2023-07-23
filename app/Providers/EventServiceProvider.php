<?php

namespace App\Providers;

use App\Events\Chat\Commands\SlowModeDisabled;
use App\Events\Chat\Commands\SlowModeEnabled;
use App\Events\Chat\DeleteMessagesEvent;
use App\Events\ClientPlayEvent;
use App\Events\ClientPlayOtherDeviceEvent;
use App\Events\StreamListenerChangeEvent;
use App\Events\StreamStatusEvent;
use App\Events\UserWaitingForProvisioningEvent;
use App\Listeners\Chat\DeleteMessages\BroadcastMessageDeletionListener;
use App\Listeners\Chat\DeleteMessages\DeleteMessagesListener;
use App\Listeners\Chat\SlowMode\AnnounceSlowModeDeactivationListener;
use App\Listeners\Chat\SlowMode\AnnounceSlowModeListener;
use App\Listeners\Chat\SlowMode\SlowModeDisableListener;
use App\Listeners\Chat\SlowMode\SlowModeEnableListener;
use App\Listeners\DispatchPaysOtherDeviceNotifcationListener;
use App\Listeners\SaveListenerCountListener;
use App\Listeners\ScalingStreamListener;
use App\Listeners\SetCacheStatusListener;
use App\Listeners\SetUserWaitingForProvisioningListener;
use App\Listeners\StopClientStreamsListener;
use App\Listeners\StreamScalingListener;
use Illuminate\Auth\Events\Registered;
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
        StreamStatusEvent::class => [
            SetCacheStatusListener::class,
            StreamScalingListener::class,
        ],
        StreamListenerChangeEvent::class => [
            SaveListenerCountListener::class,
        ],
        UserWaitingForProvisioningEvent::class => [
            SetUserWaitingForProvisioningListener::class,
        ],
        ClientPlayEvent::class => [
            DispatchPaysOtherDeviceNotifcationListener::class,
        ],
        ClientPlayOtherDeviceEvent::class => [
            StopClientStreamsListener::class,
        ],
        SlowModeEnabled::class => [
            SlowModeEnableListener::class,
            AnnounceSlowModeListener::class,
        ],
        SlowModeDisabled::class => [
            SlowModeDisableListener::class,
            AnnounceSlowModeDeactivationListener::class,
        ],
        DeleteMessagesEvent::class => [
            DeleteMessagesListener::class,
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
