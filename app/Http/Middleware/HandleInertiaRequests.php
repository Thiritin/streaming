<?php

namespace App\Http\Middleware;

use App\Services\ChatCommandService;
use App\Services\ChatMessageSanitizer;
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
        $chatCommands = [];
        $chatConfig = [];
        $emotes = [];
        $userBadge = null;

        if ($user) {
            $commandService = new ChatCommandService;
            $chatCommands = $commandService->getAvailableCommands($user);

            $sanitizer = new ChatMessageSanitizer;
            $chatConfig = [
                'maxMessageLength' => $sanitizer->getMaxLength(),
                'allowedDomains' => $sanitizer->getAllowedDomains(),
            ];

            // Get emotes available for user
            $emoteService = app(\App\Services\EmoteService::class);
            $emotes = [
                'available' => $emoteService->getAvailableEmotes($user),
                'global' => $emoteService->getGlobalEmotes(),
                'favorites' => $emoteService->getUserFavorites($user),
            ];

            // Get user badge
            $userBadge = $user->badge;
        }

        return array_merge(parent::share($request), [
            'auth' => [
                'user' => $user?->only('id', 'name', 'is_provisioning', 'timeout_expires_at', 'role', 'badge'),
                'can_access_filament' => $user?->can('filament.access'),
                'has_server_assignment' => $user ? ($user->server_id && $user->streamkey ? true : false) : false,
            ],
            'chat' => [
                'commands' => $chatCommands,
                'config' => $chatConfig,
                'emotes' => $emotes,
            ],
        ]);
    }
}
