<?php

namespace App\Console\Commands\Chat;

use App\Models\User;
use App\Models\Message;
use App\Events\MessageDeletedEvent;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class NukeCommand extends AbstractChatCommand
{
    protected string $name = 'nuke';
    protected array $aliases = ['purge'];
    protected string $description = 'Delete multiple messages from a user or time period';
    protected string $signature = '/nuke <username|all> [duration]';

    protected array $parameters = [
        'target' => [
            'required' => true,
            'type' => 'string',
            'description' => 'Username or "all" for all messages',
        ],
        'duration' => [
            'required' => false,
            'type' => 'string',
            'description' => 'Time period to nuke (e.g., 5m, 1h). Default: 5m',
        ],
    ];

    public function authorize(User $user): bool
    {
        return $user->hasPermission('chat.nuke') || 
               $user->hasRole('admin');
    }

    protected function execute(User $user, array $parameters): void
    {
        $target = $parameters['target'];
        $duration = $parameters['duration'] ?? '5m';

        // Parse duration
        $since = $this->parseDuration($duration);
        if (!$since) {
            $this->feedback($user, "Invalid duration format. Use formats like '5m', '1h'.", 'error');
            return;
        }

        // Build query
        $query = Message::where('created_at', '>=', $since)
            ->whereNull('deleted_at');

        // If target is not "all", filter by user
        if (strtolower($target) !== 'all') {
            $targetUser = User::where('name', $target)->first();
            
            if (!$targetUser) {
                $this->feedback($user, "User '{$target}' not found.", 'error');
                return;
            }

            // Prevent nuking admin/moderator messages
            if ($targetUser->hasRole('admin') || $targetUser->hasRole('moderator')) {
                $this->feedback($user, 'Cannot nuke messages from administrators or moderators.', 'error');
                return;
            }

            $query->where('user_id', $targetUser->id);
        }

        // Get messages to delete
        $messages = $query->get();
        $messageCount = $messages->count();

        if ($messageCount === 0) {
            $this->feedback($user, 'No messages found to delete.', 'info');
            return;
        }

        // Confirm if large number of messages
        if ($messageCount > 100) {
            // In a real implementation, you might want to add a confirmation step
            Log::warning('Large nuke operation attempted', [
                'moderator_id' => $user->id,
                'message_count' => $messageCount,
            ]);
        }

        // Delete messages
        $deletedUuids = [];
        foreach ($messages as $message) {
            $deletedUuids[] = $message->uuid;
            
            $message->deleted_at = now();
            $message->deleted_by_user_id = $user->id;
            $message->save();
        }

        // Broadcast deletion events (batch for efficiency)
        foreach (array_chunk($deletedUuids, 50) as $uuidBatch) {
            broadcast(new MessageDeletedEvent($uuidBatch))->toOthers();
        }

        // Send feedback
        $targetDesc = strtolower($target) === 'all' ? 'all users' : $target;
        $durationText = $this->humanizeDuration($duration);
        $this->feedback(
            $user, 
            "Nuked {$messageCount} messages from {$targetDesc} in the last {$durationText}.", 
            'success'
        );

        // Notify affected users
        if (strtolower($target) !== 'all' && isset($targetUser)) {
            $this->feedback(
                $targetUser,
                "Your recent messages have been removed by a moderator.",
                'warning'
            );
        }

        // Log the nuke operation
        Log::info('Chat nuke executed', [
            'moderator_id' => $user->id,
            'target' => $target,
            'duration' => $duration,
            'message_count' => $messageCount,
            'since' => $since,
        ]);

        // Broadcast system message
        $this->broadcastSystemMessage(
            "Chat has been cleaned by a moderator",
            'moderation'
        );
    }

    private function parseDuration(string $duration): ?Carbon
    {
        $matches = [];
        if (!preg_match('/^(\d+)([smh])$/i', $duration, $matches)) {
            return null;
        }

        $value = (int) $matches[1];
        $unit = strtolower($matches[2]);

        return match($unit) {
            's' => now()->subSeconds($value),
            'm' => now()->subMinutes($value),
            'h' => now()->subHours($value),
            default => null,
        };
    }

    private function humanizeDuration(string $duration): string
    {
        $matches = [];
        if (preg_match('/^(\d+)([smh])$/i', $duration, $matches)) {
            $value = (int) $matches[1];
            $unit = strtolower($matches[2]);
            
            return match($unit) {
                's' => $value . ' second' . ($value > 1 ? 's' : ''),
                'm' => $value . ' minute' . ($value > 1 ? 's' : ''),
                'h' => $value . ' hour' . ($value > 1 ? 's' : ''),
                default => $duration,
            };
        }
        
        return $duration;
    }

    public function examples(): array
    {
        return [
            '/nuke JohnDoe' => 'Delete last 5 minutes of messages from JohnDoe',
            '/nuke JohnDoe 10m' => 'Delete last 10 minutes of messages from JohnDoe',
            '/nuke all 1m' => 'Delete all messages from last minute',
            '/purge spammer 1h' => 'Using alias to purge last hour of messages',
        ];
    }
}