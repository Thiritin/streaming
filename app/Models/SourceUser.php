<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SourceUser extends Model
{
    protected $fillable = [
        'source_id',
        'user_id',
        'joined_at',
        'left_at',
        'last_heartbeat_at',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'joined_at' => 'datetime',
        'left_at' => 'datetime',
        'last_heartbeat_at' => 'datetime',
    ];

    /**
     * Get the source for this viewer session.
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    /**
     * Get the user for this viewer session.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Calculate watch duration dynamically.
     */
    public function getWatchDurationAttribute(): int
    {
        $endTime = $this->left_at ?? now();

        return $this->joined_at ? $endTime->diffInSeconds($this->joined_at) : 0;
    }

    /**
     * Check if the session is currently active based on heartbeat.
     */
    public function getIsActiveAttribute(): bool
    {
        if (! $this->last_heartbeat_at) {
            return false;
        }

        // Consider active if heartbeat was within last 3 minutes
        return $this->last_heartbeat_at->greaterThan(now()->subMinutes(3));
    }

    /**
     * Scope for active sessions.
     */
    public function scopeActive($query)
    {
        return $query->whereNull('left_at')
            ->where('last_heartbeat_at', '>', now()->subMinutes(3));
    }

    /**
     * Scope for current sessions (joined but not left).
     */
    public function scopeCurrent($query)
    {
        return $query->whereNull('left_at');
    }

    /**
     * Mark session as ended.
     */
    public function endSession()
    {
        $this->update(['left_at' => now()]);
    }

    /**
     * Update heartbeat timestamp.
     */
    public function heartbeat()
    {
        $this->update(['last_heartbeat_at' => now()]);
    }
}
