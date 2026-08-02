<?php

namespace App\Console\Commands\Chat;

use App\Models\User;
use App\Services\Chat\ChatModerationService;
use Illuminate\Auth\Access\AuthorizationException;

class NukeCommand extends AbstractChatCommand
{
    protected string $name = 'nuke';

    protected array $aliases = ['purge'];

    protected string $description = 'Delete recent messages from one user or from the whole chat';

    protected string $signature = '/nuke <username|all> [duration]';

    protected array $parameters = [
        'target' => [
            'required' => true,
            'type' => 'string',
            'description' => 'Username or "all" for the whole chat',
        ],
        'duration' => [
            'required' => false,
            'type' => 'string',
            'description' => 'Time period to nuke (e.g., 5m, 1h). Default: 5m',
        ],
    ];

    public function authorize(User $user): bool
    {
        return $user->hasPermission('chat.nuke') || $user->isAdmin();
    }

    protected function execute(User $user, array $parameters): void
    {
        $moderation = app(ChatModerationService::class);
        $target = (string) $parameters['target'];
        $seconds = ChatModerationService::parseDuration((string) ($parameters['duration'] ?? '5m'));

        if (! $seconds) {
            $this->feedback($user, "Invalid duration. Use formats like '5m', '1h'.", 'error');

            return;
        }

        try {
            if (strtolower($target) === 'all') {
                $count = $moderation->clearChat($user, $this->sourceId);
            } else {
                $targetUser = User::where('name', $target)->first();

                if (! $targetUser) {
                    $this->feedback($user, "User '{$target}' not found.", 'error');

                    return;
                }

                $count = $moderation->purgeUser($user, $targetUser, $this->sourceId, $seconds);
            }
        } catch (AuthorizationException $e) {
            $this->feedback($user, $e->getMessage(), 'error');

            return;
        }

        $this->feedback($user, "Removed {$count} message".($count === 1 ? '' : 's').'.', 'success');
    }

    public function examples(): array
    {
        return [
            '/nuke JohnDoe' => 'Delete the last 5 minutes of messages from JohnDoe',
            '/nuke JohnDoe 10m' => 'Delete the last 10 minutes of messages from JohnDoe',
            '/nuke all' => 'Clear the chat for this stream',
        ];
    }
}
