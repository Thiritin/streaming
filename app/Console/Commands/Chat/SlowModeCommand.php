<?php

namespace App\Console\Commands\Chat;

use App\Models\User;
use App\Services\Chat\ChatModerationService;
use App\Services\Chat\ChatSettingsService;
use Illuminate\Auth\Access\AuthorizationException;

class SlowModeCommand extends AbstractChatCommand
{
    protected string $name = 'slowmode';

    protected array $aliases = ['slow'];

    protected string $description = 'Enable, configure or disable slow mode';

    protected string $signature = '/slowmode [seconds|off]';

    protected array $parameters = [
        'duration' => [
            'required' => false,
            'type' => 'string',
            'description' => 'Seconds between messages, or "off" to disable',
        ],
    ];

    public function authorize(User $user): bool
    {
        return $user->canModerateChat();
    }

    protected function execute(User $user, array $parameters): void
    {
        $settings = app(ChatSettingsService::class);
        $duration = $parameters['duration'] ?? null;
        $current = $settings->slowModeSeconds($this->sourceId);

        if ($duration === null) {
            $this->feedback(
                $user,
                $current > 0 ? "Slow mode is set to {$current} seconds." : 'Slow mode is disabled.',
                'info',
            );

            return;
        }

        $seconds = strtolower((string) $duration) === 'off' ? 0 : ChatModerationService::parseDuration((string) $duration);

        if ($seconds === null || $seconds < 0 || $seconds > 300) {
            $this->feedback($user, 'Use a number of seconds between 1 and 300, or "off".', 'error');

            return;
        }

        try {
            app(ChatModerationService::class)->updateSettings($user, ['slow_mode_seconds' => $seconds], $this->sourceId);
        } catch (AuthorizationException $e) {
            $this->feedback($user, $e->getMessage(), 'error');

            return;
        }

        $this->feedback(
            $user,
            $seconds === 0 ? 'Slow mode disabled.' : "Slow mode enabled: {$seconds} seconds between messages.",
            'success',
        );
    }

    public function examples(): array
    {
        return [
            '/slowmode' => 'Check the current slow mode setting',
            '/slowmode 10' => 'Enable 10 second slow mode',
            '/slowmode off' => 'Disable slow mode',
        ];
    }
}
