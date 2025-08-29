<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class Source extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'location',
        'stream_key',
        'rtmp_url',
        'hls_url',
        'is_active',
        'is_primary',
        'priority',
        'metadata',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_primary' => 'boolean',
        'metadata' => 'array',
        'stream_key' => 'encrypted',
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
            if (empty($source->stream_key)) {
                $source->stream_key = Str::random(32);
            }
            // Generate RTMP and HLS URLs based on slug
            if (empty($source->rtmp_url)) {
                $source->rtmp_url = "rtmp://" . config('stream.rtmp_host', 'localhost:1935') . "/live/" . $source->stream_key;
            }
            if (empty($source->hls_url)) {
                // Use slug for HLS URL
                $source->hls_url = "http://" . config('stream.hls_host', 'localhost:8080') . "/live/" . $source->slug . ".m3u8";
            }
        });
        
        static::updating(function ($source) {
            // Update slug if name changes
            if ($source->isDirty('name') && !$source->isDirty('slug')) {
                $source->slug = Str::slug($source->name);
                // Update HLS URL if slug changes
                $source->hls_url = "http://" . config('stream.hls_host', 'localhost:8080') . "/live/" . $source->slug . ".m3u8";
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
     * Scope for active sources.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope for primary sources.
     */
    public function scopePrimary($query)
    {
        return $query->where('is_primary', true);
    }

    /**
     * Get sources ordered by priority.
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('priority', 'desc')->orderBy('name');
    }
    
    /**
     * Get HLS URLs for all quality variants.
     */
    public function getHlsUrls()
    {
        $protocol = app()->isLocal() ? 'http' : 'https';
        $host = config('stream.edge_host', request()->getHost());
        
        // Use slug for stream identification
        $streamSlug = $this->slug;
        
        $urls = [
            'master' => "{$protocol}://{$host}/live/{$streamSlug}.m3u8",
        ];
        
        // Add quality-specific URLs
        foreach (['original', 'fhd', 'hd', 'sd', 'ld', 'audio_hd', 'audio_sd'] as $quality) {
            $qualityUrl = ($quality !== 'original') ? "_{$quality}" : "";
            $urls[$quality] = "{$protocol}://{$host}/live/{$streamSlug}{$qualityUrl}.m3u8";
        }
        
        return $urls;
    }
    
    /**
     * Generate HLS URL for the source.
     */
    protected function generateHlsUrl()
    {
        return "http://" . config('stream.hls_host', 'localhost:8080') . "/live/" . $this->slug . ".m3u8";
    }
}