<?php

namespace App\Console\Commands\Chat;

use App\Models\User;
use App\Models\Timeout;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

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
        return $user->hasPermission('chat.moderate') || 
               $user->hasRole('admin') ||
               $user->hasRole('moderator');
    }

    protected function execute(User $user, array $parameters): void
    {
        $targetUsername = $parameters['username'];
        $duration = $parameters['duration'];
        $reason = $parameters['reason'] ?? null;

        // Find the target user
        $targetUser = User::where('name', $targetUsername)->first();
        
        if (!$targetUser) {
            $this->feedback($user, "User '{$targetUsername}' not found.", 'error');
            return;
        }

        // Check if trying to timeout self
        if ($targetUser->id === $user->id) {
            $this->feedback($user, 'You cannot timeout yourself.', 'error');
            return;
        }

        // Check if target is admin/moderator
        if ($targetUser->hasRole('admin') || $targetUser->hasRole('moderator')) {
            $this->feedback($user, 'You cannot timeout administrators or moderators.', 'error');
            return;
        }

        // Parse duration
        $expiresAt = $this->parseDuration($duration);
        if (!$expiresAt) {
            $this->feedback($user, "Invalid duration format. Use formats like '5m', '1h', '1d'.", 'error');
            return;
        }

        // Check for existing active timeout
        $existingTimeout = Timeout::where('user_id', $targetUser->id)
            ->where('expires_at', '>', now())
            ->first();

        if ($existingTimeout) {
            // Update existing timeout
            $existingTimeout->update([
                'expires_at' => $expiresAt,
                'reason' => $reason,
                'issued_by_user_id' => $user->id,
            ]);
        } else {
            // Create new timeout
            Timeout::create([
                'user_id' => $targetUser->id,
                'issued_by_user_id' => $user->id,
                'expires_at' => $expiresAt,
                'reason' => $reason,
            ]);
        }

        // Calculate human-readable duration
        $durationText = $this->humanizeDuration($expiresAt);

        // Send feedback to moderator
        $message = "User '{$targetUsername}' has been timed out for {$durationText}";
        if ($reason) {
            $message .= " (Reason: {$reason})";
        }
        $this->feedback($user, $message, 'success');

        // Send notification to timed out user
        $targetMessage = "You have been timed out for {$durationText}";
        if ($reason) {
            $targetMessage .= " (Reason: {$reason})";
        }
        $this->feedback($targetUser, $targetMessage, 'warning');

        // Log the timeout
        Log::info('User timeout', [
            'moderator_id' => $user->id,
            'target_user_id' => $targetUser->id,
            'duration' => $duration,
            'expires_at' => $expiresAt,
            'reason' => $reason,
        ]);

        // Broadcast system message to chat
        $this->broadcastSystemMessage(
            "{$targetUsername} has been timed out for {$durationText}",
            'timeout'
        );
    }

    private function parseDuration(string $duration): ?Carbon
    {
        $matches = [];
        if (!preg_match('/^(\d+)([smhd])$/i', $duration, $matches)) {
            return null;
        }

        $value = (int) $matches[1];
        $unit = strtolower($matches[2]);

        $now = now();
        
        return match($unit) {
            's' => $now->addSeconds($value),
            'm' => $now->addMinutes($value),
            'h' => $now->addHours($value),
            'd' => $now->addDays($value),
            default => null,
        };
    }

    private function humanizeDuration(Carbon $expiresAt): string
    {
        $diff = now()->diff($expiresAt);
        
        $parts = [];
        if ($diff->days > 0) {
            $parts[] = $diff->days . ' day' . ($diff->days > 1 ? 's' : '');
        }
        if ($diff->h > 0) {
            $parts[] = $diff->h . ' hour' . ($diff->h > 1 ? 's' : '');
        }
        if ($diff->i > 0) {
            $parts[] = $diff->i . ' minute' . ($diff->i > 1 ? 's' : '');
        }
        if (empty($parts) && $diff->s > 0) {
            $parts[] = $diff->s . ' second' . ($diff->s > 1 ? 's' : '');
        }

        return implode(', ', $parts) ?: 'a moment';
    }

    public function examples(): array
    {
        return [
            '/timeout JohnDoe 5m' => 'Timeout JohnDoe for 5 minutes',
            '/timeout JohnDoe 1h Spamming' => 'Timeout JohnDoe for 1 hour with reason',
            '/timeout JohnDoe 1d Inappropriate behavior' => 'Timeout for 1 day with reason',
            '/to JohnDoe 30m' => 'Using alias to timeout for 30 minutes',
        ];
    }
}