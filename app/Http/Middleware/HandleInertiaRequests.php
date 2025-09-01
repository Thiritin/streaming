<?php

namespace App\Http\Middleware;

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
        $chatCommands = [];
        $chatConfig = [];
        $emotes = [];

        if ($user) {
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
            ];

            // Get emotes available for user
            $emoteService = app(\App\Services\EmoteService::class);
            $emotes = [
                'available' => $emoteService->getAvailableEmotes($user),
                'global' => $emoteService->getGlobalEmotes(),
                'favorites' => $emoteService->getUserFavorites($user),
            ];

        }

        return array_merge(parent::share($request), [
            'auth' => [
                'user' => $user ? array_merge(
                    $user->only('id', 'name', 'role'),
                    ['is_staff' => $user->isStaff()]
                ) : null,
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
