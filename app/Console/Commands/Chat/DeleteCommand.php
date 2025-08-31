<?php

namespace App\Console\Commands\Chat;

use App\Models\User;
use App\Models\Message;
use App\Events\Chat\Broadcasts\BroadcastMessageDeletionIdsEvent;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class DeleteCommand extends AbstractChatCommand
{
    protected string $name = 'delete';
    protected array $aliases = ['del', 'remove', 'purge'];
    protected string $description = 'Delete all messages from a user within a time period';
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
        return $user->hasPermission('chat.moderate') || 
               $user->hasRole('admin') ||
               $user->hasRole('moderator');
    }

    protected function execute(User $user, array $parameters): void
    {
        $username = $parameters['username'];
        $duration = $parameters['duration'];

        // Find the target user
        $targetUser = User::where('name', $username)->first();
        
        if (!$targetUser) {
            $this->feedback($user, "User '{$username}' not found.", 'error');
            return;
        }

        // Parse duration to get the time range
        $cutoffTime = $this->parseDurationToTime($duration);
        if (!$cutoffTime) {
            $this->feedback($user, "Invalid duration format. Use formats like '5m', '1h', '1d'.", 'error');
            return;
        }

        // Find all messages from the user within the time period
        $messages = Message::where('user_id', $targetUser->id)
            ->where('created_at', '>=', $cutoffTime)
            ->whereNull('deleted_at')
            ->get();

        if ($messages->isEmpty()) {
            $this->feedback($user, "No messages found from '{$username}' in the last {$duration}.", 'info');
            return;
        }

        // Collect message IDs for broadcasting
        $messageIds = $messages->pluck('id')->toArray();
        $messageCount = count($messageIds);

        // Log the IDs being deleted for debugging
        Log::info('Deleting messages with IDs', [
            'message_ids' => $messageIds,
            'count' => $messageCount,
            'target_user' => $username,
        ]);

        // Soft delete all messages
        Message::whereIn('id', $messageIds)->update([
            'deleted_at' => now(),
            'deleted_by_user_id' => $user->id,
        ]);

        // Broadcast deletion event with message IDs to ALL users (including the moderator)
        broadcast(new BroadcastMessageDeletionIdsEvent($messageIds));

        // Calculate human-readable duration
        $durationText = $this->humanizeDuration($duration);

        // Send feedback to moderator
        $this->feedback($user, "Deleted {$messageCount} messages from '{$username}' from the last {$durationText}.", 'success');

        // Notify the target user
        $this->feedback($targetUser, "{$messageCount} of your messages from the last {$durationText} have been deleted by a moderator.", 'warning');

        // Log the deletion
        Log::info('Bulk message deletion by moderator', [
            'moderator_id' => $user->id,
            'target_user_id' => $targetUser->id,
            'message_count' => $messageCount,
            'duration' => $duration,
            'cutoff_time' => $cutoffTime,
        ]);

        // Broadcast system message to chat
        $this->broadcastSystemMessage(
            "Messages from {$username} in the last {$durationText} have been deleted",
            'moderation'
        );
    }

    private function parseDurationToTime(string $duration): ?Carbon
    {
        $matches = [];
        if (!preg_match('/^(\d+)([smhd])$/i', $duration, $matches)) {
            return null;
        }

        $value = (int) $matches[1];
        $unit = strtolower($matches[2]);

        $now = now();
        
        return match($unit) {
            's' => $now->subSeconds($value),
            'm' => $now->subMinutes($value),
            'h' => $now->subHours($value),
            'd' => $now->subDays($value),
            default => null,
        };
    }

    private function humanizeDuration(string $duration): string
    {
        $matches = [];
        if (!preg_match('/^(\d+)([smhd])$/i', $duration, $matches)) {
            return $duration;
        }

        $value = (int) $matches[1];
        $unit = strtolower($matches[2]);

        return match($unit) {
            's' => $value . ' second' . ($value > 1 ? 's' : ''),
            'm' => $value . ' minute' . ($value > 1 ? 's' : ''),
            'h' => $value . ' hour' . ($value > 1 ? 's' : ''),
            'd' => $value . ' day' . ($value > 1 ? 's' : ''),
            default => $duration,
        };
    }

    public function examples(): array
    {
        return [
            '/delete JohnDoe 5m' => 'Delete all messages from JohnDoe in the last 5 minutes',
            '/delete JohnDoe 1h' => 'Delete all messages from JohnDoe in the last hour',
            '/delete JohnDoe 1d' => 'Delete all messages from JohnDoe in the last day',
            '/purge SpamUser 30m' => 'Using alias to purge messages from last 30 minutes',
        ];
    }
}