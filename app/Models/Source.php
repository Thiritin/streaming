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
    ];

    protected $casts = [
        'status' => SourceStatusEnum::class,
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
            if ($source->isDirty('name') && ! $source->isDirty('slug')) {
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
     * Get sources ordered by name.
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('name');
    }

    /**
     * Get the base RTMP server URL for OBS configuration.
     * Returns URL in format: rtmp://server:port/live
     */
    public function getRtmpServerUrl()
    {
        // Get the active origin server
        $originServer = \App\Models\Server::where('type', \App\Enum\ServerTypeEnum::ORIGIN)
            ->where('status', \App\Enum\ServerStatusEnum::ACTIVE)
            ->first();

        if (!$originServer) {
            // Fallback to local config if no origin server found
            return app()->isLocal() ? 'rtmp://localhost:1935/live' : 'rtmp://localhost:1935/live';
        }

        // Use the server's hostname and port
        $port = $originServer->port ?? 1935;
        return "rtmp://{$originServer->hostname}:{$port}/live";
    }

    /**
     * Get the stream key for OBS configuration.
     * Returns: <slug>?secret=<stream_key>
     */
    public function getObsStreamKey()
    {
        return $this->slug . '?secret=' . $this->stream_key;
    }

    /**
     * Get the full RTMP push URL (for reference/testing).
     * Returns URL in format: rtmp://server:port/live/<slug>?secret=<stream_key>
     */
    public function getRtmpPushUrl()
    {
        return $this->getRtmpServerUrl() . '/' . $this->slug . '?secret=' . $this->stream_key;
    }

    /**
     * Get HLS URLs for all quality variants.
     * @param \App\Models\User|null $user Optional user to append streamkey for tracking
     */
    public function getHlsUrls($user = null)
    {
        $protocol = app()->isLocal() ? 'http' : 'https';

        // Get the appropriate server based on user assignment
        $server = null;
        if ($user) {
            // Get or assign an edge server for the user
            $server = $user->getOrAssignServer();
        }

        // If no user or no assigned server, get the first available edge server
        if (!$server) {
            $server = \App\Models\Server::where('type', \App\Enum\ServerTypeEnum::EDGE)
                ->where('status', \App\Enum\ServerStatusEnum::ACTIVE)
                ->first();
        }

        // If still no server, throw exception
        if (!$server) {
            throw new \RuntimeException('No active edge server available for HLS URLs');
        }

        // Add streamkey parameter if user is provided and has a streamkey
        $streamkeyParam = '';
        if ($user && $user->streamkey) {
            $streamkeyParam = '?streamkey=' . urlencode($user->streamkey);
        }

        $host = $server->getHostWithPort();

        return [
            'stream' => "{$protocol}://{$host}/live/{$this->slug}_fhd.m3u8{$streamkeyParam}",
            'fhd' => "{$protocol}://{$host}/live/{$this->slug}_fhd.m3u8{$streamkeyParam}",
            'hd' => "{$protocol}://{$host}/live/{$this->slug}_hd.m3u8{$streamkeyParam}",
            'sd' => "{$protocol}://{$host}/live/{$this->slug}_sd.m3u8{$streamkeyParam}",
        ];
    }

    /**
     * Get HLS URLs for internal Docker container access.
     * Used by background jobs and console commands running inside Docker.
     */
    public function getInternalHlsUrls()
    {
        // Use Docker container names for internal access when running in Docker
        if (app()->isLocal() && $this->isRunningInDocker()) {
            // Check if we can resolve the Docker service name 'edge'
            $host = 'edge';
            $testConnection = @fsockopen($host, 80, $errno, $errstr, 1);

            if (!$testConnection) {
                // Fallback to full container name if service alias doesn't work
                $host = 'ef-streaming-edge-1';
            } else {
                @fclose($testConnection);
            }

            $port = config('stream.docker.hls_port', 80);
            $protocol = 'http';

            $baseUrl = $port == 80 ? "{$protocol}://{$host}" : "{$protocol}://{$host}:{$port}";

            // No authentication token needed for internal access
            return [
                'stream' => "{$baseUrl}/live/{$this->slug}_fhd/index.m3u8",
                'fhd' => "{$baseUrl}/live/{$this->slug}_fhd/index.m3u8",
                'hd' => "{$baseUrl}/live/{$this->slug}_hd/index.m3u8",
                'sd' => "{$baseUrl}/live/{$this->slug}_sd/index.m3u8",
                'master' => "{$baseUrl}/live/{$this->slug}/index.m3u8",
            ];
        }

        // Fallback to regular URLs for non-Docker or production
        return $this->getHlsUrls();
    }

    /**
     * Check if the application is running inside a Docker container.
     */
    public function isRunningInDocker(): bool
    {
        // Check for Docker environment indicators
        return file_exists('/.dockerenv') ||
               (file_exists('/proc/1/cgroup') &&
                str_contains(file_get_contents('/proc/1/cgroup'), 'docker')) ||
               env('LARAVEL_SAIL') == 1;
    }
}
