<?php

namespace App\Http\Middleware;

use App\Services\BrandingService;
use App\Services\ChatMessageSanitizer;
use App\Services\CommandRegistry;
use Illuminate\Http\Request;
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
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();
        $chatEnabled = (bool) config('chat.enabled');
        $chatCommands = [];
        $chatConfig = [];
        $emotes = ['map' => (object) [], 'list' => []];
        $chatPermissions = [];

        if ($user && $chatEnabled) {
            // Use new CommandRegistry for commands
            $commandRegistry = app(CommandRegistry::class);
            $availableCommands = $commandRegistry->availableFor($user);

            // Transform to array format for frontend
            $chatCommands = array_map(function ($cmd) {
                return [
                    'name' => $cmd['name'],
                    'description' => $cmd['description'],
                    'syntax' => $cmd['signature'],
                    'aliases' => $cmd['aliases'] ?? [],
                ];
            }, array_values($availableCommands));

            $sanitizer = new ChatMessageSanitizer;
            $chatConfig = [
                'maxMessageLength' => $sanitizer->getMaxLength(),
                'allowedDomains' => $sanitizer->getAllowedDomains(),
                'bufferSize' => (int) config('chat.history.buffer', 300),
            ];

            $emotes = app(\App\Services\EmoteService::class)->clientPayload($user);

            $chatPermissions = [
                'moderate' => $user->canModerateChat(),
                'ban' => $user->canBanFromChat(),
                'announce' => $user->canModerateChat() || $user->hasPermission('chat.broadcast'),
                'bypass_limits' => $user->canModerateChat() || $user->hasPermission('chat.ignore.ratelimit'),
            ];
        }

        return array_merge(parent::share($request), [
            'flash' => [
                'status' => fn () => $request->session()->get('status'),
            ],
            'branding' => app(BrandingService::class)->forFrontend(),
            // Deployment-wide switches the client needs to know about: whether
            // chat exists at all, and whether a guest is allowed to browse
            // without signing in. Both come from the env, see config/chat.php
            // and config/auth.php.
            'features' => [
                'chat' => $chatEnabled,
                'authRequired' => (bool) config('auth.required'),
                'loginUrl' => route('login'),
            ],
            'auth' => [
                'user' => $user ? array_merge(
                    $user->only('id', 'name', 'role'),
                    [
                        'is_staff' => $user->isStaff(),
                        'chat_color' => $user->chat_color,
                        'badges' => $user->chatBadges(),
                    ]
                ) : null,
                'can_access_manage' => $user ? \Illuminate\Support\Facades\Gate::forUser($user)->allows('access-manage') : false,
                // Kept until /admin is removed; see docs/admin/rebuild-plan.md part 5.
                'can_access_filament' => $user?->can('filament.access'),
                'has_server_assignment' => $user ? ($user->server_id && $user->streamkey ? true : false) : false,
            ],
            'chat' => [
                'enabled' => $chatEnabled,
                'commands' => $chatCommands,
                'config' => $chatConfig,
                'emotes' => $emotes,
                'permissions' => $chatPermissions,
            ],
        ]);
    }
}
