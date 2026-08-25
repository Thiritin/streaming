<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Somebody saying a comment should not be there, and why.
 *
 * The report is what hides the comment; this row is the record of who asked and
 * what a moderator decided. Resolved rather than deleted on approval, because an
 * account that reports everything it disagrees with is only visible in the
 * reports it has left behind.
 */
class RecordingCommentReport extends Model
{
    use HasFactory;

    protected $fillable = ['recording_comment_id', 'user_id', 'message'];

    protected $casts = ['resolved_at' => 'datetime'];

    public function comment(): BelongsTo
    {
        return $this->belongsTo(RecordingComment::class, 'recording_comment_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeUnresolved(Builder $query): Builder
    {
        return $query->whereNull('resolved_at');
    }
}
