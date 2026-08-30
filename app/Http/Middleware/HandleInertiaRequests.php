<?php

namespace App\Http\Middleware;

use App\Services\BrandingService;
use App\Services\ChatMessageSanitizer;
use App\Services\CommandRegistry;
use App\Services\EmoteService;
use App\Support\Features;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * Everything expensive here is a closure. Inertia only resolves a shared
     * prop when it is actually going into the response, so a partial reload
     * (`router.reload({ only: [...] })`, and every deferred prop fetch) skips
     * the emote payload, the command registry and the chat backlog entirely
     * instead of rebuilding them to throw them away.
     *
     * The switches themselves are free either way: Features and BrandingService
     * both answer from the cache, so a full page load costs no query for them.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return array_merge(parent::share($request), [
            'flash' => [
                // Guarded: the error page shares these props from the exception
                // handler, where a request that never reached the web group has
                // no session to read.
                'status' => fn () => $request->hasSession() ? $request->session()->get('status') : null,
                // A one-line confirmation shown over the page it was triggered from.
                // Separate from `status`, which pages render inline next to a form.
                'toast' => fn () => $request->hasSession() ? $request->session()->get('toast') : null,
            ],
            'branding' => fn () => app(BrandingService::class)->forFrontend(),
            /*
             * The switches the client needs to know about: which features this
             * viewer gets, and whether a guest is allowed to browse without
             * signing in. Feature flags are the installation's switches from
             * /manage > Settings > Features with the viewer's own opt-outs from
             * /settings applied, so the client never has to combine the two.
             * authRequired comes from config/auth.php.
             */
            'features' => fn () => Features::forUser($request->user()) + [
                'authRequired' => (bool) config('auth.required'),
                'loginUrl' => route('login'),
            ],
            'auth' => fn () => $this->authProps($request),
            'chat' => fn () => $this->chatProps($request),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function authProps(Request $request): array
    {
        $user = $request->user();

        return [
            'user' => $user ? array_merge(
                $user->only('id', 'name', 'avatar', 'role'),
                [
                    'is_staff' => $user->isStaff(),
                    // Which way this account signs out: a local one posts to /logout,
                    // one the identity provider owns leaves through its front channel.
                    'is_local' => $user->isLocal(),
                    'chat_color' => $user->chat_color,
                    'badges' => $user->chatBadges(),
                ]
            ) : null,
            'can_access_manage' => $user ? Gate::forUser($user)->allows('access-manage') : false,
            // Kept until /admin is removed; see docs/admin/rebuild-plan.md part 5.
            'can_access_filament' => $user?->can('filament.access'),
        ];
    }

    /**
     * The chat half of the shared props. Empty for guests and for an
     * installation with chat switched off; emotes drop out on their own switch
     * while the rest of chat stays.
     *
     * @return array<string, mixed>
     */
    protected function chatProps(Request $request): array
    {
        $user = $request->user();
        $enabled = Features::enabledFor('chat', $user);

        $props = [
            'enabled' => $enabled,
            'commands' => [],
            'config' => [],
            'emotes' => ['map' => (object) [], 'list' => []],
            'permissions' => [],
        ];

        if (! $user || ! $enabled) {
            return $props;
        }

        $availableCommands = app(CommandRegistry::class)->availableFor($user);

        $props['commands'] = array_map(fn ($cmd) => [
            'name' => $cmd['name'],
            'description' => $cmd['description'],
            'syntax' => $cmd['signature'],
            'aliases' => $cmd['aliases'] ?? [],
        ], array_values($availableCommands));

        $sanitizer = new ChatMessageSanitizer;
        $props['config'] = [
            'maxMessageLength' => $sanitizer->getMaxLength(),
            'allowedDomains' => $sanitizer->getAllowedDomains(),
            'bufferSize' => (int) config('chat.history.buffer', 300),
        ];

        if (Features::enabledFor('emotes', $user)) {
            $props['emotes'] = app(EmoteService::class)->clientPayload($user);
        }

        $props['permissions'] = [
            'moderate' => $user->canModerateChat(),
            'ban' => $user->canBanFromChat(),
            'announce' => $user->canModerateChat() || $user->hasPermission('chat.broadcast'),
            'bypass_limits' => $user->canModerateChat() || $user->hasPermission('chat.ignore.ratelimit'),
        ];

        return $props;
    }
}
