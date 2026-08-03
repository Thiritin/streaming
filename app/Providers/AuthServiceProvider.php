<?php

namespace App\Providers;

use App\Models\Emote;
use App\Models\Recording;
use App\Models\Role;
use App\Models\Server;
use App\Models\Show;
use App\Models\Source;
use App\Models\User;
use App\Policies\EmotePolicy;
use App\Policies\RecordingPolicy;
use App\Policies\RolePolicy;
use App\Policies\ServerPolicy;
use App\Policies\ShowPolicy;
use App\Policies\SourcePolicy;
use App\Policies\UserPolicy;
use Illuminate\Auth\SessionGuard;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        Emote::class => EmotePolicy::class,
        Recording::class => RecordingPolicy::class,
        Role::class => RolePolicy::class,
        Server::class => ServerPolicy::class,
        Show::class => ShowPolicy::class,
        Source::class => SourcePolicy::class,
        User::class => UserPolicy::class,
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        // Laravel's own default keeps a remember cookie alive for five years. Cap it at
        // auth.remember_lifetime instead. Resolved lazily so booting a guard here does
        // not force the session store open on requests that never authenticate.
        Auth::resolved(function ($auth) {
            $guard = $auth->guard();

            if ($guard instanceof SessionGuard) {
                $guard->setRememberDuration(config('auth.remember_lifetime'));
            }
        });

        // Entry gate for the /manage panel. `filament.access` is kept because it is the
        // string stored on existing role rows in production; renaming it would need a
        // data migration and buys nothing.
        Gate::define('access-manage', fn (User $user) => $user->hasPermission('admin.access')
            || $user->hasPermission('filament.access')
            || $user->isStaff());
    }
}
