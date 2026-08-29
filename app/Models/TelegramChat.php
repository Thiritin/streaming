<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A chat the bot posts into. See the create migration.
 */
class TelegramChat extends Model
{
    use HasFactory;

    protected $fillable = [
        'chat_id',
        'thread_id',
        'title',
        'topic_title',
        'type',
        'enabled',
        'interactive',
        'notify_feedback',
        'notify_shows',
        'notify_recordings',
        'notify_sources',
        'notify_comments',
        'notify_health',
        'source_ids',
        'linked_by',
        'linked_at',
    ];

    protected $casts = [
        'thread_id' => 'integer',
        'enabled' => 'boolean',
        'interactive' => 'boolean',
        'notify_feedback' => 'boolean',
        'notify_comments' => 'boolean',
        'notify_health' => 'boolean',
        'notify_shows' => 'boolean',
        'notify_recordings' => 'boolean',
        'notify_sources' => 'boolean',
        'source_ids' => 'array',
        'linked_at' => 'datetime',
        'last_message_at' => 'datetime',
    ];

    public function messages(): HasMany
    {
        return $this->hasMany(TelegramMessage::class);
    }

    public function linker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'linked_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('enabled', true);
    }

    /**
     * Whether this chat wants to hear about a source at all. No list means every
     * source, which is what a control-room group wants; a list is a hall group that
     * has been told to mind its own stage.
     */
    public function coversSource(?int $sourceId): bool
    {
        $ids = $this->source_ids ?? [];

        if ($ids === []) {
            return true;
        }

        return $sourceId !== null && in_array($sourceId, array_map('intval', $ids), true);
    }

    public function sources()
    {
        $ids = $this->source_ids ?? [];

        return $ids === []
            ? Source::query()->whereRaw('1 = 0')->get()
            : Source::whereIn('id', $ids)->get();
    }

    /**
     * Stop writing here, and say why. Called when Telegram tells us the bot was
     * kicked, blocked, or the chat is gone: the row stays so an operator can see what
     * happened and re-enable it once the bot is back in the room.
     */
    public function disable(string $reason): void
    {
        $this->forceFill(['enabled' => false, 'last_error' => mb_substr($reason, 0, 250)])->save();
    }

    public function label(): string
    {
        $chat = $this->title ?: 'Chat '.$this->chat_id;

        if (! $this->isTopic()) {
            return $chat;
        }

        return $chat.' · '.($this->topic_title ?: 'topic '.$this->thread_id);
    }

    /**
     * Whether this row is one topic of a forum supergroup rather than a whole chat.
     * General has no thread id of its own, so it reads as the chat itself.
     */
    public function isTopic(): bool
    {
        return (int) $this->thread_id > 0;
    }

    /**
     * The rows for one chat id: every topic of a forum group, or the single row a plain
     * group has. What "the bot was kicked out" applies to.
     *
     * @return Builder<TelegramChat>
     */
    public static function forChat(string $chatId): Builder
    {
        return self::where('chat_id', $chatId);
    }
}
