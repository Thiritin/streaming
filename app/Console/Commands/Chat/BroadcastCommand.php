<?php

namespace App\Console\Commands\Chat;

use App\Models\User;
use App\Events\SystemMessageEvent;
use Illuminate\Support\Facades\Log;

class BroadcastCommand extends AbstractChatCommand
{
    protected string $name = 'broadcast';
    protected array $aliases = ['announce', 'bc'];
    protected string $description = 'Broadcast a system message to all users';
    protected string $signature = '/broadcast <message>';

    protected array $parameters = [
        'message' => [
            'required' => true,
            'type' => 'string',
            'description' => 'Message to broadcast',
        ],
    ];

    public function authorize(User $user): bool
    {
        return $user->hasPermission('chat.broadcast') || 
               $user->hasRole('admin') ||
               $user->hasRole('moderator');
    }

    protected function execute(User $user, array $parameters): void
    {
        $message = trim($parameters['message']);

        if (empty($message)) {
            $this->feedback($user, 'Broadcast message cannot be empty.', 'error');
            return;
        }

        // Check message length
        if (strlen($message) > 500) {
            $this->feedback($user, 'Broadcast message is too long (max 500 characters).', 'error');
            return;
        }

        // Create system message with special styling
        $systemMessage = [
            'id' => uniqid('system_'),
            'type' => 'announcement',
            'content' => $message,
            'user' => [
                'name' => 'System Announcement',
                'avatar' => null,
                'badges' => ['system'],
            ],
            'timestamp' => now()->toIso8601String(),
            'priority' => 'high',
        ];

        // Broadcast to all users
        broadcast(new SystemMessageEvent($systemMessage))->toOthers();

        // Send confirmation to moderator
        $this->feedback($user, 'System announcement broadcast successfully.', 'success');

        // Log the broadcast
        Log::info('System broadcast sent', [
            'moderator_id' => $user->id,
            'moderator_name' => $user->name,
            'message' => $message,
            'timestamp' => now(),
        ]);

        // Optionally store in database for persistence
        \App\Models\SystemMessage::create([
            'content' => $message,
            'type' => 'announcement',
            'sent_by_user_id' => $user->id,
            'priority' => 'high',
        ]);
    }

    public function examples(): array
    {
        return [
            '/broadcast Welcome to the stream!' => 'Send a welcome announcement',
            '/announce Stream starting in 5 minutes' => 'Using alias for announcement',
            '/bc Technical difficulties, please stand by' => 'Short alias for quick broadcast',
        ];
    }
}