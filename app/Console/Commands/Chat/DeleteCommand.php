<?php

namespace App\Console\Commands\Chat;

use App\Models\User;
use App\Models\Message;
use App\Events\MessageDeletedEvent;
use Illuminate\Support\Facades\Log;

class DeleteCommand extends AbstractChatCommand
{
    protected string $name = 'delete';
    protected array $aliases = ['del', 'remove'];
    protected string $description = 'Delete a specific message by ID';
    protected string $signature = '/delete <message_id>';

    protected array $parameters = [
        'message_id' => [
            'required' => true,
            'type' => 'string',
            'description' => 'ID or UUID of the message to delete',
        ],
    ];

    public function authorize(User $user): bool
    {
        return $user->hasPermission('chat.moderate') || 
               $user->hasRole('admin') ||
               $user->hasRole('moderator');
    }

    protected function execute(User $user, array $parameters): void
    {
        $messageId = $parameters['message_id'];

        // Find the message
        $message = Message::where('id', $messageId)
            ->orWhere('uuid', $messageId)
            ->first();

        if (!$message) {
            $this->feedback($user, "Message with ID '{$messageId}' not found.", 'error');
            return;
        }

        // Check if message is already deleted
        if ($message->deleted_at) {
            $this->feedback($user, 'This message has already been deleted.', 'warning');
            return;
        }

        // Store message details for logging
        $messageDetails = [
            'id' => $message->id,
            'uuid' => $message->uuid,
            'user_id' => $message->user_id,
            'content' => $message->content,
            'created_at' => $message->created_at,
        ];

        // Soft delete the message
        $message->deleted_at = now();
        $message->deleted_by_user_id = $user->id;
        $message->save();

        // Broadcast deletion event
        broadcast(new MessageDeletedEvent($message->uuid))->toOthers();

        // Send feedback to moderator
        $this->feedback($user, "Message deleted successfully (ID: {$message->uuid}).", 'success');

        // Notify the message author
        if ($message->user) {
            $this->feedback(
                $message->user, 
                'One of your messages has been deleted by a moderator.', 
                'warning'
            );
        }

        // Log the deletion
        Log::info('Message deleted by moderator', [
            'moderator_id' => $user->id,
            'message' => $messageDetails,
        ]);
    }

    public function examples(): array
    {
        return [
            '/delete 123' => 'Delete message with ID 123',
            '/delete abc-def-ghi' => 'Delete message with UUID',
            '/del 456' => 'Using alias to delete message',
        ];
    }
}