<?php

namespace App\Providers;

use App\Http\Middleware\HideManageFromGuests;
use App\Http\Middleware\LocalOnly;
use App\Http\Middleware\ShareManageProps;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to your application's "home" route.
     *
     * Typically, users are redirected here after authentication.
     *
     * @var string
     */
    public const HOME = '/';

    /**
     * Define your route model bindings, pattern filters, and other route configuration.
     */
    public function boot(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        // No rate limit for HLS endpoints - they need to handle many requests
        RateLimiter::for('hls', function (Request $request) {
            return Limit::none();
        });

        // The server API, keyed by the server in the path rather than by address: a
        // rack of edges leaves through one outbound IP, and what the limit is for is
        // that a stolen credential cannot spin one server's endpoints. Well clear of
        // a heartbeat a minute plus the handful of config fetches an install makes.
        RateLimiter::for('server-api', function (Request $request) {
            $server = $request->route('server');

            return Limit::perMinute(120)->by(
                'server-api:'.(is_object($server) ? $server->getKey() : (string) $server)
            );
        });

        $this->routes(function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));

            // Playlists, on a stack trimmed to what they actually need.
            Route::middleware('hls')
                ->group(base_path('routes/hls.php'));

            Route::middleware(['web', HideManageFromGuests::class, 'can:access-manage', ShareManageProps::class])
                ->prefix('manage')
                ->name('manage.')
                ->group(base_path('routes/manage.php'));

            // Developer account switcher. Deliberately unauthenticated, so it only
            // exists at all when running locally.
            if ($this->app->isLocal()) {
                Route::middleware(['web', LocalOnly::class])
                    ->group(base_path('routes/local.php'));
            }
        });
    }
}
