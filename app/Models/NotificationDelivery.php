<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The record that somebody was told something. See the create migration.
 *
 * Written before the send, never after: claim() is the only way a row appears, and a
 * claim that loses the unique key is a send that has already been made. That is what
 * makes "once per viewer" true across retries, overlapping scheduler ticks and a
 * recording that is unpublished and published again.
 */
class NotificationDelivery extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public const TYPE_RECORDING_PUBLISHED = 'recording.published';

    public const TYPE_SHOW_LIVE = 'show.live';

    protected $fillable = ['user_id', 'type', 'subject_id', 'channel', 'status', 'sent_at', 'error'];

    protected $casts = [
        'sent_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Take the right to send this, or answer null because somebody already has.
     *
     * insertOrIgnore rather than an insert with the unique violation caught: on
     * Postgres a failed statement aborts the surrounding transaction, so catching the
     * exception would leave the connection poisoned for everything after it. This lets
     * the database decline the row without raising - ON CONFLICT DO NOTHING there,
     * INSERT IGNORE on MySQL.
     */
    public static function claim(int $userId, string $type, ?int $subjectId, string $channel): ?self
    {
        $now = now();

        $inserted = self::query()->insertOrIgnore([
            'user_id' => $userId,
            'type' => $type,
            'subject_id' => $subjectId,
            'channel' => $channel,
            'status' => self::STATUS_PENDING,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if ($inserted === 0) {
            return null;
        }

        return self::where('user_id', $userId)
            ->where('type', $type)
            ->where('subject_id', $subjectId)
            ->where('channel', $channel)
            ->first();
    }

    public function markSent(): void
    {
        $this->forceFill(['status' => self::STATUS_SENT, 'sent_at' => now(), 'error' => null])->save();
    }

    /**
     * A send that threw. The row stays claimed rather than being deleted: a transport
     * that fails halfway is far more likely to have delivered than a retry is to be
     * wanted, and an operator can see what happened on the user's page.
     */
    public function markFailed(string $reason): void
    {
        $this->forceFill(['status' => self::STATUS_FAILED, 'error' => mb_substr($reason, 0, 250)])->save();
    }
}
