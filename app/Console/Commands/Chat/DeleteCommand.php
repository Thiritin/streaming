<?php

namespace App\Console\Commands\Chat;

use App\Models\User;
use App\Services\Chat\ChatModerationService;
use Illuminate\Auth\Access\AuthorizationException;

class DeleteCommand extends AbstractChatCommand
{
    protected string $name = 'delete';

    protected array $aliases = ['del', 'remove'];

    protected string $description = "Delete a user's messages from the last N minutes";

    protected string $signature = '/delete <username> <duration>';

    protected array $parameters = [
        'username' => [
            'required' => true,
            'type' => 'string',
            'description' => 'Username whose messages to delete',
        ],
        'duration' => [
            'required' => true,
            'type' => 'string',
            'description' => 'Time period to delete messages from (e.g., 5m, 1h, 1d)',
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
            $count = $moderation->purgeUser($user, $targetUser, $this->sourceId, $seconds);
        } catch (AuthorizationException $e) {
            $this->feedback($user, $e->getMessage(), 'error');

            return;
        }

        $this->feedback(
            $user,
            $count === 0
                ? "No messages from {$targetUser->name} in the last ".$moderation->humanizeSeconds($seconds).'.'
                : "Deleted {$count} message".($count === 1 ? '' : 's')." from {$targetUser->name}.",
            $count === 0 ? 'info' : 'success',
        );
    }

    public function examples(): array
    {
        return [
            '/delete JohnDoe 5m' => 'Delete all messages from JohnDoe in the last 5 minutes',
            '/del SpamUser 30m' => 'Using alias to delete messages from the last 30 minutes',
        ];
    }
}
