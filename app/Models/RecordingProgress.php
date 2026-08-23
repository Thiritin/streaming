<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Where a signed-in viewer got to in a recording.
 *
 * One row per viewer per recording, rewritten in place by the player every few
 * seconds. Guests keep no progress at all: there is nothing to key a row on, and
 * a browser-local position could not feed the Continue watching shelf, which is
 * assembled server-side with everything else on the archive page.
 */
class RecordingProgress extends Model
{
    protected $table = 'recording_progress';

    protected $fillable = [
        'user_id',
        'recording_id',
        'position',
        'duration',
        'completed',
    ];

    protected $casts = [
        'position' => 'integer',
        'duration' => 'integer',
        'completed' => 'boolean',
    ];

    /**
     * Watched far enough that offering to resume is more annoying than useful.
     */
    public const COMPLETE_AT = 0.97;

    /**
     * Below this the viewer has barely started, so the recording does not belong
     * on Continue watching and the tile shows no bar.
     */
    public const STARTED_AT = 0.02;

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function recording()
    {
        return $this->belongsTo(Recording::class);
    }

    public function fraction(): float
    {
        $duration = $this->duration ?: $this->recording?->duration;

        if (! $duration) {
            return 0.0;
        }

        return min(1.0, max(0.0, $this->position / $duration));
    }
}
