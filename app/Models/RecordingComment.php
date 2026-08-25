<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One thing a viewer said under a recording. See the create migration.
 *
 * The thread is one level deep: a comment, and replies flat underneath it. Depth
 * is not a column that can drift - a row with a parent is a reply and a row
 * without one is not - and `RecordingCommentController::store()` re-points a
 * reply-to-a-reply at the top of its thread rather than letting one grow.
 */
class RecordingComment extends Model
{
    use HasFactory;

    protected $fillable = ['recording_id', 'user_id', 'parent_id', 'body'];

    protected $casts = [
        'edited_at' => 'datetime',
        'hidden_at' => 'datetime',
        'approved_at' => 'datetime',
    ];

    public function recording(): BelongsTo
    {
        return $this->belongsTo(Recording::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * Who hearted it. The count is what orders the thread, so it is read with
     * withCount rather than by loading the rows.
     */
    public function hearts(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'recording_comment_hearts')->withTimestamps();
    }

    public function reports(): HasMany
    {
        return $this->hasMany(RecordingCommentReport::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * What a given viewer is allowed to see.
     *
     * A reported comment goes dark for the room straight away, but never for the
     * person who wrote it: somebody watching their own words disappear starts
     * again in a new thread, while somebody who cannot tell has nothing to work
     * around. Moderators see it too, flagged, because the page is where the
     * quickest decision about it gets made.
     */
    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        if ($user && $user->can('manage', self::class)) {
            return $query;
        }

        return $query->where(function (Builder $query) use ($user) {
            $query->whereNull('hidden_at');

            if ($user) {
                $query->orWhere('user_id', $user->id);
            }
        });
    }

    /**
     * Rewrite the body.
     *
     * An edit drops the approval with it: a comment approved as harmless and then
     * rewritten has not been looked at, and leaving the stamp on would make an
     * approval the way to post something no report can take down.
     */
    public function editBody(string $body): void
    {
        $this->forceFill([
            'body' => $body,
            'edited_at' => now(),
            'approved_at' => null,
            'approved_by' => null,
        ])->save();
    }

    public function isHidden(): bool
    {
        return $this->hidden_at !== null;
    }

    /**
     * Hide it on a report. An approved comment stays put: it has been looked at,
     * and re-reporting it is how one account would otherwise silence another for
     * good.
     */
    public function hideOnReport(): void
    {
        if ($this->approved_at !== null || $this->hidden_at !== null) {
            return;
        }

        $this->forceFill(['hidden_at' => now()])->save();
    }

    public function approve(?User $moderator = null): void
    {
        $this->forceFill([
            'hidden_at' => null,
            'approved_at' => now(),
            'approved_by' => $moderator?->id,
        ])->save();

        $this->reports()->unresolved()->update(['resolved_at' => now()]);
    }

    public function scopeRoots(Builder $query): Builder
    {
        return $query->whereNull('parent_id');
    }

    public function isReply(): bool
    {
        return $this->parent_id !== null;
    }

    /**
     * A cell's worth of the body, for the panel's list.
     */
    public function excerpt(int $length = 120): string
    {
        $body = preg_replace('/\s+/u', ' ', (string) $this->body);

        return mb_strlen($body) > $length
            ? mb_substr($body, 0, $length - 1).'...'
            : $body;
    }
}
