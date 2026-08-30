<?php

namespace App\Providers;

use App\Models\Recording;
use App\Models\Show;
use App\Models\Source;
use App\Models\User;
use App\Notifications\Channels\TelegramChannel;
use App\Observers\RecordingObserver;
use App\Observers\ShowObserver;
use App\Observers\SourceObserver;
use App\Observers\UserObserver;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {

        Http::macro('identity', function () {
            return Http::baseUrl(config('services.oidc.url'));
        });

        Http::macro('attsrv', function () {
            return Http::acceptJson()
                ->withToken(Session::get('access_token'))
                ->baseUrl(config('services.attsrv.url'));
        });

        // Viewer notifications over the bot's private chats. Nothing to do with the
        // operator chats in `telegram_chats`, which the notifier writes to directly.
        Notification::extend('telegram', fn ($app) => $app->make(TelegramChannel::class));

        User::observe(UserObserver::class);
        Source::observe(SourceObserver::class);
        Show::observe(ShowObserver::class);
        Recording::observe(RecordingObserver::class);
    }
}
