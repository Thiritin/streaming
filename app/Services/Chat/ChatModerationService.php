<?php

namespace App\Services\Chat;

use App\Events\Chat\Broadcasts\ChatMessageEvent;
use App\Events\Chat\Broadcasts\ChatMessagesDeletedEvent;
use App\Events\Chat\Broadcasts\ChatNoticeEvent;
use App\Events\Chat\Broadcasts\ChatUserStateEvent;
use App\Models\ChatBan;
use App\Models\ChatModerationLog;
use App\Models\Message;
use App\Models\Timeout;
use App\Models\User;
use App\Support\Chat\Broadcast;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;

/**
 * Every moderation action funnels through here, whether it came from the mod menu,
 * a quick action on a message, or a legacy slash command.
 */
class ChatModerationService
{
    public function __construct(protected ChatSettingsService $settings) {}

    /**
     * Timeout a user for a number of seconds.
     */
    public function timeout(User $moderator, User $target, int $seconds, ?string $reason = null, ?int $sourceId = null): Timeout
    {
        $this->assertCanModerate($moderator, $target);

        $seconds = max(1, min($seconds, 14 * 24 * 3600));
        $expiresAt = now()->addSeconds($seconds);

        $timeout = Timeout::updateOrCreate(
            ['user_id' => $target->id],
            [
                'issued_by_user_id' => $moderator->id,
                'expires_at' => $expiresAt,
                'reason' => $reason,
            ],
        );

        $this->log('timeout', $moderator, $target, $sourceId, $reason, ['seconds' => $seconds]);

        Broadcast::send(new ChatUserStateEvent($target, 'timed_out', $reason, $seconds, $sourceId));
        Broadcast::send(new ChatNoticeEvent(
            "{$target->name} was timed out for ".$this->humanizeSeconds($seconds).($reason ? " ({$reason})" : ''),
            $sourceId,
            'warning',
            modsOnly: true,
        ));

        return $timeout;
    }

    public function removeTimeout(User $moderator, User $target, ?int $sourceId = null): void
    {
        $this->assertCanModerate($moderator, $target);

        Timeout::where('user_id', $target->id)->delete();

        $this->log('untimeout', $moderator, $target, $sourceId);

        Broadcast::send(new ChatUserStateEvent($target, 'cleared', null, null, $sourceId));
        Broadcast::send(new ChatNoticeEvent("{$target->name}'s timeout was removed", $sourceId, 'info', modsOnly: true));
    }

    /**
     * Ban a user from chat. A null $expiresAt means permanent.
     */
    public function ban(User $moderator, User $target, ?string $reason = null, ?Carbon $expiresAt = null, ?int $sourceId = null): ChatBan
    {
        if (! $moderator->canBanFromChat()) {
            throw new AuthorizationException('You do not have permission to ban users.');
        }

        $this->assertCanModerate($moderator, $target);

        $target->chatBans()->active()->update(['lifted_at' => now(), 'lifted_by_user_id' => $moderator->id]);

        $ban = ChatBan::create([
            'user_id' => $target->id,
            'banned_by_user_id' => $moderator->id,
            'reason' => $reason,
            'expires_at' => $expiresAt,
        ]);

        $this->log('ban', $moderator, $target, $sourceId, $reason, ['expires_at' => $expiresAt?->toIso8601String()]);

        Broadcast::send(new ChatUserStateEvent(
            $target,
            'banned',
            $reason,
            $expiresAt ? (int) now()->diffInSeconds($expiresAt) : null,
            $sourceId,
        ));
        Broadcast::send(new ChatNoticeEvent(
            "{$target->name} was banned from chat".($reason ? " ({$reason})" : ''),
            $sourceId,
            'error',
            modsOnly: true,
        ));

        return $ban;
    }

    public function unban(User $moderator, User $target, ?int $sourceId = null): void
    {
        if (! $moderator->canBanFromChat()) {
            throw new AuthorizationException('You do not have permission to unban users.');
        }

        $target->chatBans()->active()->update(['lifted_at' => now(), 'lifted_by_user_id' => $moderator->id]);

        $this->log('unban', $moderator, $target, $sourceId);

        Broadcast::send(new ChatUserStateEvent($target, 'cleared', null, null, $sourceId));
        Broadcast::send(new ChatNoticeEvent("{$target->name} was unbanned", $sourceId, 'info', modsOnly: true));
    }

    /**
     * Delete a single message.
     */
    public function deleteMessage(User $moderator, Message $message): void
    {
        if ($message->user_id !== $moderator->id) {
            $this->assertCanModerate($moderator, $message->user);
        }

        $message->update(['deleted_by_user_id' => $moderator->id]);
        $message->delete();

        $this->log('delete_message', $moderator, $message->user, $message->source_id, null, [
            'message_id' => $message->id,
        ]);

        Broadcast::send(new ChatMessagesDeletedEvent(
            [$message->id],
            $message->source_id,
            $message->user?->name,
            $moderator->name,
        ));
    }

    /**
     * Delete a user's recent messages. $withinSeconds null deletes everything in the source.
     *
     * @return int number of deleted messages
     */
    public function purgeUser(User $moderator, User $target, ?int $sourceId = null, ?int $withinSeconds = null): int
    {
        $this->assertCanModerate($moderator, $target);

        $query = Message::where('user_id', $target->id)->whereNull('deleted_at');

        if ($sourceId !== null) {
            $query->where('source_id', $sourceId);
        }

        if ($withinSeconds !== null) {
            $query->where('created_at', '>=', now()->subSeconds($withinSeconds));
        }

        $messages = $query->get();

        if ($messages->isEmpty()) {
            return 0;
        }

        Message::whereIn('id', $messages->pluck('id'))->update([
            'deleted_at' => now(),
            'deleted_by_user_id' => $moderator->id,
        ]);

        $this->log('purge', $moderator, $target, $sourceId, null, [
            'count' => $messages->count(),
            'within_seconds' => $withinSeconds,
        ]);

        foreach ($messages->groupBy('source_id') as $groupSourceId => $group) {
            Broadcast::send(new ChatMessagesDeletedEvent(
                $group->pluck('id')->all(),
                $groupSourceId !== null ? (int) $groupSourceId : null,
                $target->name,
                $moderator->name,
            ));
        }

        return $messages->count();
    }

    /**
     * Wipe the visible chat log for a source.
     *
     * @return int number of deleted messages
     */
    public function clearChat(User $moderator, ?int $sourceId = null): int
    {
        if (! $moderator->canModerateChat()) {
            throw new AuthorizationException('You do not have permission to moderate chat.');
        }

        $query = Message::whereNull('deleted_at');

        if ($sourceId !== null) {
            $query->where('source_id', $sourceId);
        }

        $ids = $query->pluck('id');

        if ($ids->isEmpty()) {
            return 0;
        }

        Message::whereIn('id', $ids)->update([
            'deleted_at' => now(),
            'deleted_by_user_id' => $moderator->id,
        ]);

        $this->log('clear_chat', $moderator, null, $sourceId, null, ['count' => $ids->count()]);

        Broadcast::send(new ChatMessagesDeletedEvent($ids->all(), $sourceId, null, $moderator->name));
        Broadcast::send(new ChatNoticeEvent('Chat was cleared by a moderator', $sourceId, 'warning'));

        return $ids->count();
    }

    /**
     * Post a highlighted announcement into a source's chat.
     */
    public function announce(User $moderator, string $text, ?int $sourceId = null): Message
    {
        if (! $moderator->canModerateChat() && ! $moderator->hasPermission('chat.broadcast')) {
            throw new AuthorizationException('You do not have permission to send announcements.');
        }

        $message = Message::create([
            'message' => $text,
            'user_id' => null,
            'source_id' => $sourceId,
            'is_command' => false,
            'type' => 'announcement',
            'priority' => 'high',
            'metadata' => [
                'sent_by_user_id' => $moderator->id,
                'sent_by_user_name' => $moderator->name,
            ],
        ]);

        $this->log('announce', $moderator, null, $sourceId, null, ['message_id' => $message->id]);

        Broadcast::send(new ChatMessageEvent($message));

        return $message;
    }

    /**
     * Update chat modes for a source.
     *
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    public function updateSettings(User $moderator, array $changes, ?int $sourceId = null): array
    {
        if (! $moderator->canModerateChat()) {
            throw new AuthorizationException('You do not have permission to moderate chat.');
        }

        $settings = $this->settings->update($changes, $sourceId);

        $this->log('settings', $moderator, null, $sourceId, null, $changes);

        Broadcast::send(new ChatNoticeEvent($this->describeSettings($settings), $sourceId, 'info'));

        return $settings;
    }

    /**
     * A moderator may not act on someone whose highest role outranks their own.
     */
    public function canActOn(User $moderator, ?User $target): bool
    {
        if (! $moderator->canModerateChat()) {
            return false;
        }

        if (! $target) {
            return true;
        }

        // Nobody moderates themselves; deleting your own message goes a different route.
        if ($target->id === $moderator->id) {
            return false;
        }

        if ($moderator->isAdmin()) {
            return true;
        }

        // A moderator cannot touch admins or fellow moderators.
        return ! $target->isAdmin() && ! $target->canModerateChat();
    }

    protected function assertCanModerate(User $moderator, ?User $target): void
    {
        if (! $moderator->canModerateChat()) {
            throw new AuthorizationException('You do not have permission to moderate chat.');
        }

        if (! $this->canActOn($moderator, $target)) {
            throw new AuthorizationException('You cannot moderate this user.');
        }
    }

    protected function log(string $action, ?User $moderator, ?User $target, ?int $sourceId, ?string $reason = null, array $metadata = []): void
    {
        ChatModerationLog::create([
            'action' => $action,
            'moderator_id' => $moderator?->id,
            'target_user_id' => $target?->id,
            'source_id' => $sourceId,
            'reason' => $reason,
            'metadata' => $metadata ?: null,
        ]);
    }

    public function humanizeSeconds(int $seconds): string
    {
        return match (true) {
            $seconds < 60 => $seconds.' second'.($seconds === 1 ? '' : 's'),
            $seconds < 3600 => intdiv($seconds, 60).' minute'.(intdiv($seconds, 60) === 1 ? '' : 's'),
            $seconds < 86400 => intdiv($seconds, 3600).' hour'.(intdiv($seconds, 3600) === 1 ? '' : 's'),
            default => intdiv($seconds, 86400).' day'.(intdiv($seconds, 86400) === 1 ? '' : 's'),
        };
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    protected function describeSettings(array $settings): string
    {
        $active = [];

        if ($settings['slow_mode_seconds'] > 0) {
            $active[] = 'slow mode '.$settings['slow_mode_seconds'].'s';
        }

        if ($settings['emote_only']) {
            $active[] = 'emote-only';
        }

        if ($settings['sponsors_only']) {
            $active[] = 'sponsors-only';
        }

        return $active === []
            ? 'Chat restrictions were turned off'
            : 'Chat mode updated: '.implode(', ', $active);
    }

    /**
     * Parse durations like `10s`, `5m`, `1h`, `2d` or a plain number of seconds.
     */
    public static function parseDuration(string $duration): ?int
    {
        $duration = trim(strtolower($duration));

        if (is_numeric($duration)) {
            return (int) $duration;
        }

        if (! preg_match('/^(\d+)\s*(s|m|h|d)$/', $duration, $matches)) {
            return null;
        }

        return (int) $matches[1] * match ($matches[2]) {
            's' => 1,
            'm' => 60,
            'h' => 3600,
            'd' => 86400,
        };
    }
}
