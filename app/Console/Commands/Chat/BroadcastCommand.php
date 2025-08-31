<?php

namespace App\Console\Commands\Chat;

use App\Models\User;
use App\Models\Message;
use App\Events\Chat\Broadcasts\SystemAnnouncementEvent;
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
        $messageContent = trim($parameters['message'] ?? '');

        if (empty($messageContent)) {
            $this->feedback($user, 'Broadcast message cannot be empty.', 'error');
            return;
        }

        // Check message length
        if (strlen($messageContent) > 500) {
            $this->feedback($user, 'Broadcast message is too long (max 500 characters).', 'error');
            return;
        }

        // Create the message in the database
        $message = Message::create([
            'message' => $messageContent,
            'user_id' => null, // System messages don't have a user
            'is_command' => false,
            'type' => 'announcement',
            'priority' => 'high',
            'metadata' => [
                'sent_by_user_id' => $user->id,
                'sent_by_user_name' => $user->name,
            ]
        ]);

        // Broadcast the announcement using the dedicated system announcement event
        broadcast(new SystemAnnouncementEvent($message));

        // Log the broadcast
        Log::info('System broadcast sent', [
            'moderator_id' => $user->id,
            'moderator_name' => $user->name,
            'message' => $messageContent,
            'timestamp' => now(),
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