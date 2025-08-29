<?php

namespace App\Http\Middleware;

use App\Services\ChatCommandService;
use App\Services\ChatMessageSanitizer;
use Illuminate\Http\Request;
use Inertia\Middleware;
use Tightenco\Ziggy\Ziggy;

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
    public function version(Request $request): string|null
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
        
        if ($user) {
            $commandService = new ChatCommandService();
            $chatCommands = $commandService->getAvailableCommands($user);
            
            $sanitizer = new ChatMessageSanitizer();
            $chatConfig = [
                'maxMessageLength' => $sanitizer->getMaxLength(),
                'allowedDomains' => $sanitizer->getAllowedDomains(),
            ];
        }
        
        return array_merge(parent::share($request), [
            'auth' => [
                'user' => $user?->only('id', 'name', 'is_provisioning', 'timeout_expires_at', 'role'),
                'can_access_filament' => $user?->can('filament.access'),
            ],
            'chat' => [
                'commands' => $chatCommands,
                'config' => $chatConfig,
            ],
        ]);
    }
}
