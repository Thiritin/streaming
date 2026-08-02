<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Message extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'message',
        'user_id',
        'source_id',
        'reply_to_id',
        'is_command',
        'type',
        'priority',
        'metadata',
        'deleted_by_user_id',
    ];

    protected $casts = [
        'metadata' => 'array',
        'is_command' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reply_to_id');
    }

    public function deletedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by_user_id');
    }

    /**
     * Messages that belong in the visible chat log for the given user.
     */
    public function scopeVisibleTo(Builder $query, ?User $user = null): Builder
    {
        return $query->where(function (Builder $query) use ($user) {
            $query->where('is_command', false)
                ->orWhereIn('type', ['announcement', 'system']);

            if ($user) {
                $query->orWhere(fn (Builder $q) => $q->where('is_command', true)->where('user_id', $user->id));
            }
        });
    }

    /**
     * The raw message text, with legacy stored markup converted back to plain text.
     *
     * Messages used to be stored HTML-escaped with `<emote>` tags baked in. The client
     * now receives raw text and renders emotes/mentions/links itself, so old rows are
     * normalised on the way out instead of being migrated in place.
     */
    public function getBodyAttribute(): string
    {
        $body = (string) $this->message;

        if (! str_contains($body, '<emote') && ! str_contains($body, '&')) {
            return $body;
        }

        $body = preg_replace('/<emote data-name="([^"]+)"[^>]*><\/emote>/', ':$1:', $body);
        $body = str_replace('&#8203;', '', $body);

        return html_entity_decode($body, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
