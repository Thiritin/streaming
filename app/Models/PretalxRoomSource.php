<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A pretalx room pinned to one of our channels, so the import screen only asks for the
 * mapping once per event.
 */
class PretalxRoomSource extends Model
{
    protected $fillable = [
        'event_slug',
        'room_id',
        'room_name',
        'source_id',
    ];

    protected $casts = [
        'room_id' => 'integer',
        'source_id' => 'integer',
    ];

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    /**
     * The saved mapping for an event as room id => source id, with unmapped rooms absent.
     *
     * @return array<int, int>
     */
    public static function mapFor(string $eventSlug): array
    {
        return static::query()
            ->where('event_slug', $eventSlug)
            ->whereNotNull('source_id')
            ->pluck('source_id', 'room_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
