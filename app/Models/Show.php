<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
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
        'thumbnail_url',
        'thumbnail_updated_at',
        'thumbnail_capture_error',
        'viewer_count',
        'peak_viewer_count',
        'is_featured',
        'priority',
        'tags',
        'metadata',
        'server_id',
    ];

    protected $casts = [
        'scheduled_start' => 'datetime',
        'scheduled_end' => 'datetime',
        'actual_start' => 'datetime',
        'actual_end' => 'datetime',
        'thumbnail_updated_at' => 'datetime',
        'is_featured' => 'boolean',
        'tags' => 'array',
        'metadata' => 'array',
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
     * Scope for featured shows.
     */
    public function scopeFeatured($query)
    {
        return $query->where('is_featured', true);
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
     * Get HLS URLs from the show's source.
     */
    public function getHlsUrls()
    {
        if (! $this->source) {
            return null;
        }

        return $this->source->getHlsUrls();
    }

    /**
     * Capture a screenshot from the live stream.
     */
    public function captureScreenshot()
    {
        if ($this->status !== 'live' || ! $this->source) {
            throw new \Exception('Show must be live with an active source to capture screenshot');
        }

        // Get the HLS URL for the stream
        $hlsUrls = $this->getHlsUrls();
        if (! $hlsUrls || ! isset($hlsUrls['stream'])) {
            throw new \Exception('No HLS stream URL available');
        }

        $streamUrl = $hlsUrls['stream'];

        // Generate a unique filename
        $filename = 'shows/thumbnails/'.$this->slug.'_'.now()->timestamp.'.jpg';
        $storagePath = storage_path('app/public/'.$filename);

        // Ensure directory exists
        $directory = dirname($storagePath);
        if (! file_exists($directory)) {
            mkdir($directory, 0755, true);
        }

        try {
            // Use ffmpeg to capture a frame from the HLS stream
            $command = sprintf(
                'ffmpeg -i "%s" -vframes 1 -q:v 2 -f image2 "%s" 2>&1',
                $streamUrl,
                $storagePath
            );

            exec($command, $output, $returnCode);

            if ($returnCode !== 0) {
                $this->update([
                    'thumbnail_capture_error' => implode("\n", $output),
                    'thumbnail_updated_at' => now(),
                ]);
                throw new \Exception('FFmpeg failed: '.implode("\n", $output));
            }

            // Update the show with the new thumbnail
            $this->update([
                'thumbnail_url' => $filename,
                'thumbnail_updated_at' => now(),
                'thumbnail_capture_error' => null,
            ]);

            return $filename;

        } catch (\Exception $e) {
            \Log::error('Screenshot capture failed for show '.$this->id.': '.$e->getMessage());
            throw $e;
        }
    }

    /**
     * Get the stream URL for this show.
     */
    public function getStreamUrl()
    {
        $urls = $this->getHlsUrls();

        return $urls ? ($urls['stream'] ?? $urls['master'] ?? null) : null;
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
}
