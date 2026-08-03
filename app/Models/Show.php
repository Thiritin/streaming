<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class Show extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'slug',
        'description',
        'source_id',
        'scheduled_start',
        'scheduled_end',
        'actual_start',
        'actual_end',
        'status',
        'auto_mode',
        'auto_stop_at',
        'announce_recording',
        'thumbnail_path',
        'thumbnail_updated_at',
        'thumbnail_capture_error',
        'viewer_count',
        'peak_viewer_count',
        'priority',
        'tags',
        'metadata',
        'required_roles',
        'server_id',
        // Set by the pretalx import; its presence is what stops a slot being imported twice.
        'pretalx_slot_id',
    ];

    protected $casts = [
        'scheduled_start' => 'datetime',
        'scheduled_end' => 'datetime',
        'actual_start' => 'datetime',
        'actual_end' => 'datetime',
        'thumbnail_updated_at' => 'datetime',
        'auto_stop_at' => 'datetime',
        'auto_mode' => 'boolean',
        'announce_recording' => 'boolean',
        'tags' => 'array',
        'metadata' => 'array',
        'required_roles' => 'array',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($show) {
            if (empty($show->slug)) {
                $show->slug = Str::slug($show->title.'-'.Carbon::parse($show->scheduled_start)->format('Y-m-d'));
            }
        });

        static::updating(function ($show) {
            // Update peak viewer count if current is higher
            if ($show->viewer_count > $show->peak_viewer_count) {
                $show->peak_viewer_count = $show->viewer_count;
            }
        });
    }

    /**
     * Get the source for this show.
     */
    public function source()
    {
        return $this->belongsTo(Source::class);
    }

    /**
     * Get the server handling this show.
     */
    public function server()
    {
        return $this->belongsTo(Server::class);
    }

    /**
     * Get the users watching this show through its source.
     */
    public function viewers()
    {
        // Use hasManyThrough to get users through source_users
        return $this->hasManyThrough(
            User::class,
            SourceUser::class,
            'source_id',     // Foreign key on source_users table
            'id',            // Foreign key on users table
            'source_id',     // Local key on shows table
            'user_id'        // Local key on source_users table
        );
    }

    /**
     * Get viewer sessions for this show.
     */
    public function viewerSessions()
    {
        return $this->hasMany(SourceUser::class, 'source_id', 'source_id');
    }

    /**
     * Get show statistics for this show.
     */
    public function showStatistics()
    {
        return $this->hasMany(ShowStatistic::class);
    }

    /**
     * Recordings cut from this show's slot.
     *
     * hasMany rather than hasOne: a recording is a time range over the source's archive,
     * so a long block can be sliced into several published pieces without re-recording
     * anything. `recording()` stays as the convenience accessor for the common 1:1 case.
     */
    public function recordings()
    {
        return $this->hasMany(Recording::class);
    }

    /**
     * The primary recording for this show, if one has been cut.
     */
    public function recording()
    {
        return $this->hasOne(Recording::class)->latestOfMany();
    }

    /**
     * Get currently active viewers.
     */
    public function activeViewers()
    {
        // Get active viewers from the source
        if ($this->source) {
            return $this->source->activeViewers();
        }

        return collect([]);
    }

    /**
     * Go live - start the actual stream.
     */
    public function goLive()
    {
        $this->update([
            'status' => 'live',
            'actual_start' => now(),
        ]);

        // Dispatch event for notifications
        event(new \App\Events\ShowWentLive($this));
    }

    /**
     * End the livestream.
     */
    public function endLivestream()
    {
        $this->update([
            'status' => 'ended',
            'actual_end' => now(),
        ]);

        // Mark all active viewers as left in the source
        if ($this->source) {
            $this->source->activeViewers()->update([
                'left_at' => now(),
            ]);
        }

        // Dispatch event for notifications
        event(new \App\Events\ShowEnded($this));
    }

    /**
     * Cancel the show.
     */
    public function cancel()
    {
        $this->update([
            'status' => 'cancelled',
        ]);

        event(new \App\Events\ShowCancelled($this));
    }

    /**
     * Check if show is currently live.
     */
    public function isLive()
    {
        return $this->status === 'live';
    }

    /**
     * Check if show is scheduled.
     */
    public function isScheduled()
    {
        return $this->status === 'scheduled';
    }

    /**
     * Check if show has ended.
     */
    public function hasEnded()
    {
        return $this->status === 'ended';
    }

    /**
     * Check if show is cancelled.
     */
    public function isCancelled()
    {
        return $this->status === 'cancelled';
    }

    /**
     * Get the RTMP push URL for this show.
     */
    public function getRtmpUrl()
    {
        if ($this->server) {
            return "rtmp://{$this->server->hostname}/live/{$this->source->stream_key}";
        }

        return $this->source->rtmp_url;
    }

    /**
     * Scope for live shows.
     */
    public function scopeLive($query)
    {
        return $query->where('status', 'live');
    }

    /**
     * Scope for scheduled shows.
     */
    public function scopeScheduled($query)
    {
        return $query->where('status', 'scheduled');
    }

    /**
     * Scope for upcoming shows.
     */
    public function scopeUpcoming($query)
    {
        return $query->scheduled()
            ->where('scheduled_start', '>', now())
            ->orderBy('scheduled_start');
    }

    /**
     * Scope for shows happening today.
     */
    public function scopeToday($query)
    {
        return $query->whereDate('scheduled_start', today());
    }

    /**
     * Get duration in minutes.
     */
    public function getDurationAttribute()
    {
        if ($this->actual_start && $this->actual_end) {
            return $this->actual_start->diffInMinutes($this->actual_end);
        }

        return $this->scheduled_start->diffInMinutes($this->scheduled_end);
    }

    /**
     * The description as HTML.
     *
     * Descriptions are markdown - that is what pretalx abstracts are written in, and what
     * the form accepts - and the stored value stays markdown so it can be edited. This is
     * the rendered, sanitised form for display.
     */
    public function getDescriptionHtmlAttribute(): ?string
    {
        return \App\Support\Markdown::render($this->description);
    }

    /**
     * Get the full URL for the thumbnail path stored in database.
     * Returns a signed URL for S3 access.
     */
    public function getThumbnailUrlAttribute()
    {
        $value = $this->thumbnail_path;
        if (! $value) {
            return null;
        }

        // If it's already a full URL, return it
        if (filter_var($value, FILTER_VALIDATE_URL)) {
            return $value;
        }

        // Return a temporary signed URL (valid for 1 hour)
        try {
            return Storage::disk('s3')->temporaryUrl($value, now()->addHour());
        } catch (\Exception $e) {
            // Fallback to regular URL if temporary URL fails
            return Storage::disk('s3')->url($value);
        }
    }

    /**
     * Get HLS master playlist URL from the show's source.
     */
    public function getHlsUrl()
    {
        if (! $this->source) {
            return null;
        }

        return $this->source->getHlsUrl();
    }

    /**
     * Capture a screenshot from the live stream.
     */
    public function captureScreenshot()
    {
        if ($this->status !== 'live' || ! $this->source) {
            throw new \Exception('Show must be live with an active source to capture screenshot');
        }

        // Use the ThumbnailService to capture the screenshot
        // This ensures proper URL handling for Docker environments
        $thumbnailService = app(\App\Services\ThumbnailService::class);
        $result = $thumbnailService->captureFromHls($this);

        if (! $result) {
            // Get the error from the model if it was set
            $error = $this->thumbnail_capture_error ?? 'Failed to capture screenshot';
            throw new \Exception($error);
        }

        return $result;
    }

    /**
     * Get the stream URL for this show.
     */
    public function getStreamUrl()
    {
        return $this->getHlsUrl();
    }

    /**
     * Check if show can be watched (is live or about to start).
     */
    public function canWatch()
    {
        // Allow watching if live
        if ($this->status === 'live') {
            return true;
        }

        // Allow watching 5 minutes before scheduled start
        if ($this->status === 'scheduled' && $this->scheduled_start) {
            return now()->diffInMinutes($this->scheduled_start, false) <= 5;
        }

        return false;
    }

    /**
     * Get formatted duration.
     */
    public function getFormattedDurationAttribute()
    {
        $duration = $this->duration;
        $hours = floor($duration / 60);
        $minutes = $duration % 60;

        if ($hours > 0) {
            return "{$hours}h {$minutes}m";
        }

        return "{$minutes}m";
    }

    /**
     * Update viewer count.
     */
    public function updateViewerCount()
    {
        $count = $this->source ? $this->source->activeViewers()->count() : 0;
        $this->update(['viewer_count' => $count]);
    }

    /**
     * Check if show is in auto mode.
     */
    public function isAutoMode()
    {
        return $this->auto_mode === true;
    }

    /**
     * The moment an auto-mode show must stop, whatever the source is doing.
     *
     * Falls back to `scheduled_end` when no explicit hard stop is set, which is what auto
     * mode did before the column existed. See docs/admin/auto-mode.md.
     */
    public function autoStopAt(): ?Carbon
    {
        if (! $this->isAutoMode()) {
            return null;
        }

        return $this->auto_stop_at ?? $this->scheduled_end;
    }

    /**
     * Whether the hard stop has passed. This is the dance safety net: a show nobody
     * remembered to end stops on its own instead of recording all night.
     */
    public function isPastAutoStop(): bool
    {
        $stop = $this->autoStopAt();

        return $stop !== null && $stop->lte(now());
    }

    /**
     * Private means only listed roles may watch, and nobody else even sees the show.
     * Public means anyone signed in.
     */
    public function isPrivate(): bool
    {
        return ! empty($this->required_roles);
    }

    /**
     * Check if show is within scheduled time window.
     */
    public function isWithinScheduledTime()
    {
        $now = now();

        // Use lte (less than or equal) and gte (greater than or equal) for inclusive boundaries
        return $this->scheduled_start->lte($now) && $this->scheduled_end->gte($now);
    }

    /**
     * Check if show has passed its scheduled end time.
     */
    public function isPastScheduledEnd()
    {
        // Use lt (less than) for exclusive comparison
        return $this->scheduled_end->lt(now());
    }

    /**
     * Scope for auto mode shows.
     */
    public function scopeAutoMode($query)
    {
        return $query->where('auto_mode', true);
    }

    /**
     * Check if show has access restrictions.
     */
    public function hasAccessRestriction(): bool
    {
        return ! empty($this->required_roles);
    }

    /**
     * Check if a user can access this show.
     */
    public function canBeAccessedBy(?User $user): bool
    {
        // No restrictions = everyone can access
        if (! $this->hasAccessRestriction()) {
            return true;
        }

        // Must be logged in to access restricted content
        if (! $user) {
            return false;
        }

        // Check if user has any of the required roles
        return $user->hasAnyRole($this->required_roles);
    }

    /**
     * Scope for shows accessible by a user.
     */
    public function scopeAccessibleBy($query, ?User $user)
    {
        return $query->where(function ($q) use ($user) {
            // No restrictions (null or empty array)
            $q->whereNull('required_roles')
                ->orWhereJsonLength('required_roles', 0);

            // Or user has one of the required roles
            if ($user) {
                $userRoles = $user->roles()->pluck('slug')->toArray();
                foreach ($userRoles as $role) {
                    $q->orWhereJsonContains('required_roles', $role);
                }
            }
        });
    }
}
