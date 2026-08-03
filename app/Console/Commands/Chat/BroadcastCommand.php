<?php

namespace App\Console\Commands\Chat;

use App\Models\User;
use App\Services\Chat\ChatModerationService;
use App\Services\ChatMessageSanitizer;
use Illuminate\Auth\Access\AuthorizationException;

class BroadcastCommand extends AbstractChatCommand
{
    protected string $name = 'broadcast';

    protected array $aliases = ['announce', 'bc'];

    protected string $description = 'Post a highlighted announcement in chat';

    protected string $signature = '/broadcast <message>';

    protected array $parameters = [
        'message' => [
            'required' => true,
            'type' => 'string',
            'description' => 'Message to announce',
        ],
    ];

    public function authorize(User $user): bool
    {
        return $user->canModerateChat() || $user->hasPermission('chat.broadcast');
    }

    protected function execute(User $user, array $parameters): void
    {
        $body = (new ChatMessageSanitizer)->sanitize((string) ($parameters['message'] ?? ''));

        if ($body === '') {
            $this->feedback($user, 'Announcement cannot be empty.', 'error');

            return;
        }

        try {
            app(ChatModerationService::class)->announce($user, $body, $this->sourceId);
        } catch (AuthorizationException $e) {
            $this->feedback($user, $e->getMessage(), 'error');

            return;
        }

        $this->feedback($user, 'Announcement sent.', 'success');
    }

    public function examples(): array
    {
        return [
            '/broadcast Welcome to the stream!' => 'Send a welcome announcement',
            '/bc Technical difficulties, please stand by' => 'Short alias for a quick announcement',
        ];
    }
}
