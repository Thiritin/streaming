<?php

namespace App\Models;

use App\Enum\SourceStatusEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class Source extends Model
{
    use HasFactory;

    protected $fillable = [
        'status',
        'name',
        'slug',
        'description',
        'stream_key',
        'priority',
        'is_featured',
    ];

    protected $casts = [
        'status' => SourceStatusEnum::class,
        'stream_key' => 'encrypted',
        'is_featured' => 'boolean',
    ];

    protected $hidden = [
        'stream_key',
    ];

    /**
     * Validation rules for the model.
     */
    public static function rules($id = null)
    {
        return [
            'name' => 'required|string|max:255',
            'slug' => ['required', 'string', 'max:255', Rule::unique('sources')->ignore($id)],
            'stream_key' => ['required', 'string', Rule::unique('sources')->ignore($id)],
        ];
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($source) {
            if (empty($source->slug)) {
                $source->slug = Str::slug($source->name);
            }
            // Generate a secure stream key for authentication
            if (empty($source->stream_key)) {
                // Generate a secure random key for the secret parameter
                $source->stream_key = Str::random(32);
            }
        });

        // Featuring a channel demotes the others. Without this the flag silently
        // becomes "one of the featured ones", and which one wins depends on insertion
        // order, which is exactly the ambiguity the flag replaced.
        static::saved(function ($source) {
            if ($source->is_featured && $source->wasChanged('is_featured')) {
                static::where('id', '!=', $source->id)
                    ->where('is_featured', true)
                    ->update(['is_featured' => false]);
            }
        });

        static::updating(function ($source) {
            // The slug is the RTMP ingress path and the HLS route key, so it is
            // immutable after creation. Renaming a source must not move it.
            if ($source->isDirty('slug')) {
                $source->slug = $source->getOriginal('slug');
            }
            // Stream key should remain separate from slug for security
            // Only regenerate if explicitly cleared
            if ($source->isDirty('stream_key') && empty($source->stream_key)) {
                $source->stream_key = Str::random(32);
            }
        });
    }

    /**
     * Get the shows for this source.
     */
    public function shows()
    {
        return $this->hasMany(Show::class);
    }

    /**
     * Get viewer sessions for this source.
     */
    public function viewers()
    {
        return $this->hasMany(SourceUser::class);
    }

    /**
     * Get active viewer sessions for this source.
     */
    public function activeViewers()
    {
        return $this->hasMany(SourceUser::class)
            ->whereNull('left_at')
            ->where('last_heartbeat_at', '>', now()->subMinutes(3));
    }

    /**
     * Get currently live shows for this source.
     */
    public function liveShows()
    {
        return $this->shows()->where('status', 'live');
    }

    /**
     * Get upcoming shows for this source.
     */
    public function upcomingShows()
    {
        return $this->shows()
            ->where('status', 'scheduled')
            ->where('scheduled_start', '>', now())
            ->orderBy('scheduled_start');
    }

    /**
     * Check if source has any live shows.
     */
    public function hasLiveShow()
    {
        return $this->liveShows()->exists();
    }

    /**
     * Get the current live show if any.
     */
    public function currentLiveShow()
    {
        return $this->liveShows()->first();
    }

    /**
     * Get sources ordered by priority (descending) then by name.
     *
     * This is display order for the schedule grid and channel lists. It is deliberately
     * not how the featured channel is chosen: see featured().
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('priority', 'desc')->orderBy('name');
    }

    /**
     * The channel the site promotes: the stage hero, and where an ended show sends people.
     *
     * Falls back to display order when nothing is flagged, so a fresh install still has a
     * sensible hero rather than none.
     */
    public static function featured(): ?self
    {
        return static::where('is_featured', true)->first()
            ?? static::ordered()->first();
    }

    /**
     * Get the base RTMP server URL for OBS configuration.
     * Returns URL in format: rtmp://server:port/ingress
     *
     * Null when no origin server is active: there is no address to push to yet. This used
     * to read ->hostname off null and take the whole page down with it, which is exactly
     * the moment - origin down - when an operator most needs the page.
     */
    public function getRtmpServerUrl(): ?string
    {
        $originServer = \App\Models\Server::where('type', \App\Enum\ServerTypeEnum::ORIGIN)
            ->where('status', \App\Enum\ServerStatusEnum::ACTIVE)
            ->first();

        if (! $originServer) {
            return null;
        }

        return "rtmp://{$originServer->hostname}:1935/ingress";
    }

    /**
     * Get the stream key for OBS configuration.
     * Returns: <slug>?secret=<stream_key>STr
     */
    public function getObsStreamKey()
    {
        return $this->slug.'?secret='.$this->stream_key;
    }

    /**
     * Get the full RTMP push URL (for reference/testing).
     * Returns URL in format: rtmp://server:port/ingress/<slug>?secret=<stream_key>
     */
    public function getRtmpPushUrl()
    {
        return $this->getRtmpServerUrl().'/'.$this->slug.'?secret='.$this->stream_key;
    }

    /**
     * Get HLS master playlist URL.
     */
    public function getHlsUrl()
    {
        // Local dev loops bypass the edge proxy entirely; see config/stream.php.
        if (config('stream.dev_streams')) {
            return asset("dev-streams/{$this->slug}/index.m3u8");
        }

        return route('hls.master', ['stream' => $this->slug]);
    }
}
