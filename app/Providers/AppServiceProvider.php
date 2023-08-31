<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Http;
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

        //User::observe(\App\Observers\UserObserver::class);
    }
}
