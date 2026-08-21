<?php

namespace App\Models;

use App\Enum\ServerStatusEnum;
use App\Enum\ServerTypeEnum;
use App\Enum\SourceStatusEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class Source extends Model
{
    use HasFactory;

    /** How long the playable answer is reused on the playlist path. */
    private const PLAYABLE_CACHE_TTL = 5;

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
        //
        // Wrapped in a transaction with row locking to prevent concurrent promotions from
        // leaving no explicit featured source (race condition where both transactions see
        // the other source as featured and demote it).
        static::saved(function ($source) {
            if ($source->is_featured && $source->wasChanged('is_featured')) {
                \DB::transaction(function () use ($source) {
                    static::where('id', '!=', $source->id)
                        ->where('is_featured', true)
                        ->lockForUpdate()
                        ->get()
                        ->each(fn ($other) => $other->update(['is_featured' => false]));
                });
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
     * Whether viewers may open this channel at all right now.
     *
     * Plenty of channels ingest around the clock - a stage camera left up through
     * setup, a hall that is empty until the doors open - and a feed arriving is not
     * permission to watch it. A live show is what opens a channel, and the only thing
     * that does. Source status is a separate question: a live show on a channel that
     * has stopped sending stays open, so the viewer gets the technical-difficulties
     * page rather than a dead end.
     */
    public function isPlayable(): bool
    {
        if (array_key_exists('live_shows_count', $this->attributes)) {
            return (int) $this->attributes['live_shows_count'] > 0;
        }

        return $this->hasLiveShow();
    }

    /**
     * The same question for a whole list, without a query per row.
     */
    public function scopeWithLiveShowCount($query)
    {
        return $query->withCount([
            'shows as live_shows_count' => fn ($q) => $q->where('status', 'live'),
        ]);
    }

    /**
     * The same question on the playlist path, where it is asked once per poll per
     * viewer and must not cost a query.
     *
     * The window is short, and going live or ending drops the entry outright through
     * ShowObserver, so it only ever applies to a status changed behind the model.
     */
    public static function playable(string $slug): bool
    {
        return (bool) Cache::remember(
            self::playableCacheKey($slug),
            self::PLAYABLE_CACHE_TTL,
            fn () => static::where('slug', $slug)
                ->whereHas('shows', fn ($q) => $q->where('status', 'live'))
                ->exists(),
        );
    }

    public static function forgetPlayable(string $slug): void
    {
        Cache::forget(self::playableCacheKey($slug));
    }

    private static function playableCacheKey(string $slug): string
    {
        return 'source_playable:'.$slug;
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
        $originServer = Server::where('type', ServerTypeEnum::ORIGIN)
            ->where('status', ServerStatusEnum::ACTIVE)
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
