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
        'stream_key',
        'rtmp_url',
        'hls_url',
        'is_active',
        'is_primary',
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
            // Generate a secure stream key for authentication
            if (empty($source->stream_key)) {
                // Generate a secure random key for the secret parameter
                $source->stream_key = Str::random(32);
            }
        });
        
        static::updating(function ($source) {
            // Update slug if name changes
            if ($source->isDirty('name') && !$source->isDirty('slug')) {
                $source->slug = Str::slug($source->name);
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
     * Get sources ordered by name.
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('name');
    }
    
    /**
     * Get HLS URLs for all quality variants.
     */
    public function getHlsUrls()
    {
        $protocol = app()->isLocal() ? 'http' : 'https';
        
        // For local development with Docker, use the SRS HLS server directly
        if (app()->isLocal()) {
            $host = 'localhost:' . env('HLS_EDGE_PORT', '8085'); // HLS edge port
            $protocol = 'http';
        } else {
            // Get the server handling the current show with this source
            $server = null;
            $currentShow = $this->currentLiveShow();
            if ($currentShow && $currentShow->server) {
                $server = $currentShow->server;
            } else {
                // Fallback to any available edge server
                $server = \App\Models\Server::where('type', 'edge')
                    ->where('status', \App\Enum\ServerStatusEnum::ACTIVE)
                    ->first();
            }
            
            // Use server's getHostWithPort method to handle port properly
            if ($server) {
                $host = $server->getHostWithPort();
                // Determine protocol based on port
                if ($server->port === 443) {
                    $protocol = 'https';
                } elseif ($server->port === 80) {
                    $protocol = 'http';
                }
            } else {
                $host = config('stream.edge_host', request()->getHost());
            }
        }
        
        // Use source slug for stream identification
        // When using multi-bitrate HLS with FFmpeg:
        // Original stream: /live/[slug]/index.m3u8
        // HD quality: /live/[slug]_hd/index.m3u8
        // SD quality: /live/[slug]_sd/index.m3u8
        // LD quality: /live/[slug]_ld/index.m3u8
        
        $urls = [
            'stream' => "{$protocol}://{$host}/live/{$this->slug}/index.m3u8", // Original stream
            'hd' => "{$protocol}://{$host}/live/{$this->slug}_hd/index.m3u8",  // HD 720p
            'sd' => "{$protocol}://{$host}/live/{$this->slug}_sd/index.m3u8",  // SD 480p
            'ld' => "{$protocol}://{$host}/live/{$this->slug}_ld/index.m3u8",  // LD 360p
        ];
        
        return $urls;
    }
    
    /**
     * Get the base RTMP server URL for OBS configuration.
     * Returns URL in format: rtmp://server:port/live
     */
    public function getRtmpServerUrl()
    {
        // Get the active origin server
        $originServer = \App\Models\Server::where('type', 'origin')
            ->where('status', \App\Enum\ServerStatusEnum::ACTIVE)
            ->first();
            
        $baseUrl = '';
        
        if (!$originServer) {
            // Fallback to config if no origin server found
            // For local Docker, use port 1935 (correctly mapped now)
            $defaultPort = app()->isLocal() ? '1935' : '1935';
            $host = config('stream.rtmp_host', 'localhost:' . $defaultPort);
            $baseUrl = "rtmp://" . $host;
        } else if ($originServer->hetzner_id === 'manual') {
            // For manual/local servers
            // For local Docker development, use port 1935 (now correctly mapped)
            // Otherwise use the server's configured port or default to 1935
            if (app()->isLocal() && $originServer->hostname === 'localhost') {
                $baseUrl = "rtmp://localhost:1935";
            } else {
                // Use hostname for Docker containers (OSSRS standard)
                $port = $originServer->port ?? 1935;
                $baseUrl = "rtmp://" . $originServer->hostname . ":" . $port;
            }
        } else {
            // For cloud servers
            $port = $originServer->port ?? 1935;
            $baseUrl = "rtmp://" . $originServer->hostname . ":" . $port;
        }
        
        // Return base URL with app name for OBS Server field
        return $baseUrl . "/live";
    }
    
    /**
     * Get the stream key for OBS configuration.
     * Returns: <slug>?secret=<stream_key>
     */
    public function getObsStreamKey()
    {
        return $this->slug . "?secret=" . $this->stream_key;
    }
    
    /**
     * Get the full RTMP push URL (for reference/testing).
     * Returns URL in format: rtmp://server:port/live/<slug>?secret=<stream_key>
     */
    public function getRtmpPushUrl()
    {
        return $this->getRtmpServerUrl() . "/" . $this->slug . "?secret=" . $this->stream_key;
    }
    
    /**
     * Get the full RTMP URL with stream key.
     * This returns the URL with stream key as parameter for OBS.
     */
    public function getFullRtmpUrl()
    {
        return $this->getRtmpPushUrl();
    }
    
    /**
     * Generate HLS URL for the source.
     */
    protected function generateHlsUrl()
    {
        return "http://" . config('stream.hls_host', 'localhost:8080') . "/live/" . $this->slug . ".m3u8";
    }
}