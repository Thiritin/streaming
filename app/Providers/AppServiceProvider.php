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
use App\Services\Cloud\CloudManager;
use App\Services\Cloud\ServerProvider;
use App\Services\Dns\DnsManager;
use App\Services\Dns\DnsProvider;
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
        // One instance per driver name, so the delete path can resolve the driver a row
        // was written by while the create path resolves the one that is selected now.
        $this->app->singleton(DnsManager::class);
        $this->app->singleton(CloudManager::class);

        $this->app->bind(DnsProvider::class, fn ($app) => $app->make(DnsManager::class)->driver());
        $this->app->bind(ServerProvider::class, fn ($app) => $app->make(CloudManager::class)->driver());
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
