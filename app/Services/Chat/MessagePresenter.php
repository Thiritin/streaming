<?php

namespace App\Services\Chat;

use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Single source of truth for the shape of a chat message on the wire.
 *
 * The client receives raw text plus author metadata and does its own rendering,
 * so nothing here may contain markup.
 */
class MessagePresenter
{
    /** @var array<int, array> */
    protected array $authorMemo = [];

    /**
     * @return array<string, mixed>
     */
    public function present(Message $message): array
    {
        $author = $message->user_id ? $this->author($message->user ?? User::find($message->user_id)) : null;

        return [
            'id' => $message->id,
            'type' => $message->type ?: 'user',
            'body' => $message->body,
            'user' => $author,
            'name' => $author['name'] ?? $this->systemName($message),
            'color' => $author['color'] ?? '#f6cb21',
            'badges' => $author['badges'] ?? [],
            'time' => $message->created_at->format('H:i'),
            'timestamp' => $message->created_at->toIso8601String(),
            'is_command' => (bool) $message->is_command,
            'priority' => $message->priority,
            'metadata' => $message->metadata,
            'source_id' => $message->source_id,
            'reply_to' => $this->replyTo($message),
        ];
    }

    /**
     * @param  iterable<Message>  $messages
     * @return array<int, array<string, mixed>>
     */
    public function presentMany(iterable $messages): array
    {
        $presented = [];

        foreach ($messages as $message) {
            $presented[] = $this->present($message);
        }

        return $presented;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function author(?User $user): ?array
    {
        if (! $user) {
            return null;
        }

        if (isset($this->authorMemo[$user->id])) {
            return $this->authorMemo[$user->id];
        }

        return $this->authorMemo[$user->id] = Cache::remember(
            'chat_author_'.$user->id,
            300,
            fn () => [
                'id' => $user->id,
                'name' => $user->name,
                'color' => $user->chat_color,
                'badges' => $user->chatBadges(),
            ],
        );
    }

    public static function forgetAuthor(int $userId): void
    {
        Cache::forget('chat_author_'.$userId);
    }

    protected function systemName(Message $message): string
    {
        return $message->type === 'announcement' ? 'Announcement' : 'System';
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function replyTo(Message $message): ?array
    {
        if (! $message->reply_to_id) {
            return null;
        }

        $parent = $message->relationLoaded('replyTo') ? $message->replyTo : $message->replyTo()->first();

        if (! $parent) {
            return null;
        }

        return [
            'id' => $parent->id,
            'name' => $parent->user?->name,
            'body' => mb_strimwidth($parent->body, 0, 120, '…'),
        ];
    }
}
