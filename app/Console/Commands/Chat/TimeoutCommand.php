<?php

namespace App\Console\Commands\Chat;

use App\Models\User;
use App\Services\Chat\ChatModerationService;
use Illuminate\Auth\Access\AuthorizationException;

class TimeoutCommand extends AbstractChatCommand
{
    protected string $name = 'timeout';

    protected array $aliases = ['to', 'mute'];

    protected string $description = 'Timeout a user from sending messages';

    protected string $signature = '/timeout <username> <duration> [reason]';

    protected array $parameters = [
        'username' => [
            'required' => true,
            'type' => 'string',
            'description' => 'Username to timeout',
        ],
        'duration' => [
            'required' => true,
            'type' => 'string',
            'description' => 'Duration (e.g., 5m, 1h, 1d)',
        ],
        'reason' => [
            'required' => false,
            'type' => 'string',
            'description' => 'Reason for the timeout',
        ],
    ];

    public function authorize(User $user): bool
    {
        return $user->canModerateChat();
    }

    protected function execute(User $user, array $parameters): void
    {
        $moderation = app(ChatModerationService::class);

        $targetUser = User::where('name', $parameters['username'])->first();

        if (! $targetUser) {
            $this->feedback($user, "User '{$parameters['username']}' not found.", 'error');

            return;
        }

        $seconds = ChatModerationService::parseDuration((string) $parameters['duration']);

        if (! $seconds) {
            $this->feedback($user, "Invalid duration. Use formats like '5m', '1h', '1d'.", 'error');

            return;
        }

        try {
            $moderation->timeout($user, $targetUser, $seconds, $parameters['reason'] ?? null, $this->sourceId);
        } catch (AuthorizationException $e) {
            $this->feedback($user, $e->getMessage(), 'error');

            return;
        }

        $this->feedback(
            $user,
            "{$targetUser->name} was timed out for ".$moderation->humanizeSeconds($seconds).'.',
            'success',
        );
    }

    public function examples(): array
    {
        return [
            '/timeout JohnDoe 5m' => 'Timeout JohnDoe for 5 minutes',
            '/timeout JohnDoe 1h Spamming' => 'Timeout JohnDoe for 1 hour with reason',
            '/to JohnDoe 30m' => 'Using alias to timeout for 30 minutes',
        ];
    }
}
