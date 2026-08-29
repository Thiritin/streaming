<?php

namespace App\Providers;

use App\Models\Category;
use App\Models\DisplayScreen;
use App\Models\EmbedKey;
use App\Models\Emote;
use App\Models\Event;
use App\Models\FeedbackReport;
use App\Models\Recording;
use App\Models\RecordingComment;
use App\Models\Role;
use App\Models\Server;
use App\Models\Show;
use App\Models\Source;
use App\Models\TelegramChat;
use App\Models\User;
use App\Policies\CategoryPolicy;
use App\Policies\DisplayScreenPolicy;
use App\Policies\EmbedKeyPolicy;
use App\Policies\EmotePolicy;
use App\Policies\EventPolicy;
use App\Policies\FeedbackReportPolicy;
use App\Policies\RecordingCommentPolicy;
use App\Policies\RecordingPolicy;
use App\Policies\RolePolicy;
use App\Policies\ServerPolicy;
use App\Policies\ShowPolicy;
use App\Policies\SourcePolicy;
use App\Policies\TelegramChatPolicy;
use App\Policies\UserPolicy;
use App\Support\ManageAccess;
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
        DisplayScreen::class => DisplayScreenPolicy::class,
        EmbedKey::class => EmbedKeyPolicy::class,
        Category::class => CategoryPolicy::class,
        Event::class => EventPolicy::class,
        Emote::class => EmotePolicy::class,
        FeedbackReport::class => FeedbackReportPolicy::class,
        Recording::class => RecordingPolicy::class,
        RecordingComment::class => RecordingCommentPolicy::class,
        Role::class => RolePolicy::class,
        Server::class => ServerPolicy::class,
        Show::class => ShowPolicy::class,
        Source::class => SourcePolicy::class,
        TelegramChat::class => TelegramChatPolicy::class,
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

        // Entry gate for the /manage panel. Defined in App\Support\ManageAccess, which
        // the sign-in safeguard asks the same question of: it has to know whether any
        // administrator is left, and the two answers must not drift.
        Gate::define('access-manage', fn (User $user) => ManageAccess::allows($user));
    }
}
