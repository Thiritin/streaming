<?php

namespace App\Models;

use App\Enum\ServerStatusEnum;
use App\Enum\ServerTypeEnum;
use App\Jobs\Server\DeleteServerJob;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class Server extends Model
{
    protected $guarded = [];

    protected $casts = [
        'status' => ServerStatusEnum::class,
        'type' => ServerTypeEnum::class,
        'port' => 'integer',
        'max_clients' => 'integer',
        'viewer_count' => 'integer',
        'last_heartbeat' => 'datetime',
        'last_health_check' => 'datetime',
        'immutable' => 'boolean',
    ];

    protected $attributes = [
        'port' => 443,
        'max_clients' => 1000,
        'viewer_count' => 0,
    ];

    /**
     * Boot the model
     */
    protected static function boot()
    {
        parent::boot();
        
        static::creating(function ($server) {
            // Auto-generate shared secret if not provided
            if (empty($server->shared_secret)) {
                $server->shared_secret = Str::random(40);
            }
            
            // Set default status if not provided
            if (empty($server->status)) {
                $server->status = ServerStatusEnum::PROVISIONING;
            }
        });
    }

    /**
     * Get the active origin server (there should only be one)
     */
    public static function getOrigin()
    {
        return static::where('type', ServerTypeEnum::ORIGIN)
            ->where('status', ServerStatusEnum::ACTIVE)
            ->first();
    }

    /**
     * Get all active edge servers
     */
    public static function getActiveEdges()
    {
        return static::where('type', ServerTypeEnum::EDGE)
            ->where('status', ServerStatusEnum::ACTIVE)
            ->get();
    }

    /**
     * Check if this server can become the origin (only one origin allowed)
     */
    public function canBecomeOrigin(): bool
    {
        if ($this->type !== ServerTypeEnum::ORIGIN) {
            return false;
        }

        // Check if another origin is already active
        $existingOrigin = static::where('type', ServerTypeEnum::ORIGIN)
            ->where('status', ServerStatusEnum::ACTIVE)
            ->where('id', '!=', $this->id)
            ->exists();

        return !$existingOrigin;
    }

    /**
     * Get the HLS base URL for this server
     */
    public function getHlsBaseUrl(): string
    {
        if ($this->type === ServerTypeEnum::ORIGIN) {
            // Origin server serves HLS directly from SRS
            $protocol = $this->port === 443 ? 'https' : 'http';
            $port = in_array($this->port, [80, 443]) ? '' : ':' . $this->port;
            return "{$protocol}://{$this->hostname}{$port}";
        } else {
            // Edge server proxies from origin
            $protocol = $this->port === 443 ? 'https' : 'http';
            $port = in_array($this->port, [80, 443]) ? '' : ':' . $this->port;
            return "{$protocol}://{$this->hostname}{$port}";
        }
    }

    /**
     * Get the full HLS path for a stream
     */
    public function getHlsUrl(string $streamSlug, string $quality = 'fhd'): string
    {
        $baseUrl = $this->getHlsBaseUrl();
        
        if ($this->type === ServerTypeEnum::ORIGIN) {
            // Origin server path structure from SRS
            $hlsPath = $this->hls_path ?: '/live';
            return "{$baseUrl}{$hlsPath}/{$streamSlug}_{$quality}/index.m3u8";
        } else {
            // Edge server proxies the same path
            return "{$baseUrl}/live/{$streamSlug}_{$quality}/index.m3u8";
        }
    }

    /**
     * Get the origin URL for edge servers to proxy from
     */
    public function getOriginUrl(): ?string
    {
        if ($this->type === ServerTypeEnum::EDGE) {
            $origin = static::getOrigin();
            if ($origin) {
                return $origin->getHlsBaseUrl();
            }
        }
        return null;
    }

    /**
     * Update viewer count from heartbeat
     */
    public function updateViewerCount(int $count): void
    {
        $this->update([
            'viewer_count' => $count,
            'last_heartbeat' => now(),
        ]);
    }

    /**
     * Check if server has recent heartbeat
     */
    public function hasRecentHeartbeat(): bool
    {
        if (!$this->last_heartbeat) {
            return false;
        }
        
        // Consider heartbeat stale after 1 minute
        return $this->last_heartbeat->gt(now()->subMinute());
    }

    /**
     * Get total viewers across all edge servers
     */
    public static function getTotalViewers(): int
    {
        return static::where('type', ServerTypeEnum::EDGE)
            ->where('status', ServerStatusEnum::ACTIVE)
            ->sum('viewer_count');
    }

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function deprovision()
    {
        // Allow deprovisioning any server without restrictions
        DeleteServerJob::dispatch($this);
    }

    /**
     * Override delete to unassign users first
     */
    public function delete()
    {
        // Unassign all users from this server before deletion
        $this->users()->update(['server_id' => null]);
        
        return parent::delete();
    }

    /**
     * Check if this is a Hetzner cloud server
     */
    public function isHetznerServer(): bool
    {
        return !empty($this->hetzner_id);
    }

    /**
     * Check if this server can use internal networking with another server
     */
    public function canUseInternalNetworkWith(?Server $otherServer): bool
    {
        if (!$otherServer) {
            return false;
        }

        // Both servers must be Hetzner servers
        if (!$this->isHetznerServer() || !$otherServer->isHetznerServer()) {
            return false;
        }

        // Both servers must have internal IPs
        if (empty($this->internal_ip) || empty($otherServer->internal_ip)) {
            return false;
        }

        return true;
    }

    public function isReady(): bool
    {
        // For manual/local servers (null hetzner_id), assume they're ready if active
        if (!$this->hetzner_id && $this->status === ServerStatusEnum::ACTIVE) {
            return true;
        }

        $proto = 'https';
        $hostname = $this->hostname;

        if ($this->type === ServerTypeEnum::ORIGIN) {
            // Origin servers run SRS with API on port 1985
            $proto = 'http';
            $hostname = $this->ip . ':1985';
        }

        // For local Docker containers (null hetzner_id), use http
        if (!$this->hetzner_id) {
            $proto = 'http';
            // Use the hostname directly for Docker containers
            if ($this->type === ServerTypeEnum::EDGE) {
                $hostname = $this->hostname . ':' . $this->port;
            }
        }

        try {
            $request = Http::timeout(5)->get($proto . '://' . $hostname . '/ready');
        } catch (ClientException|ServerException|ConnectionException $e) {
            return false;
        }

        return $request->successful() && $request->json('code') === 0;
    }

    public function isInUse(): bool
    {
        if ($this->type === ServerTypeEnum::ORIGIN) {
            // Origin is in use if any streams are live
            return \App\Models\Source::where('status', \App\Enum\SourceStatusEnum::ONLINE)->exists();
        }
        
        // Edge is in use if it has viewers
        return $this->viewer_count > 0;
    }

    /**
     * Get host with port for URLs
     */
    public function getHostWithPort(): string
    {
        if (in_array($this->port, [80, 443])) {
            return $this->hostname;
        }
        return $this->hostname . ':' . $this->port;
    }

    /**
     * Perform health check on the server
     */
    public function performHealthCheck(): bool
    {
        // Only check edge servers with nginx /health endpoint
        if ($this->type !== ServerTypeEnum::EDGE) {
            return true;
        }

        try {
            $protocol = in_array($this->port, [443]) ? 'https' : 'http';
            $url = "{$protocol}://{$this->getHostWithPort()}/health";
            
            $response = Http::timeout(5)->get($url);
            
            if ($response->successful()) {
                $this->update([
                    'health_status' => 'healthy',
                    'last_health_check' => now(),
                    'health_check_message' => 'Health check passed',
                ]);
                return true;
            } else {
                $errorMessage = "HTTP {$response->status()}: {$response->body()}";
                
                // Log the health check failure
                \Log::error('Server health check failed', [
                    'server_id' => $this->id,
                    'hostname' => $this->hostname,
                    'url' => $url,
                    'http_status' => $response->status(),
                    'response_body' => $response->body(),
                    'message' => $errorMessage,
                ]);
                
                $this->update([
                    'health_status' => 'unhealthy',
                    'last_health_check' => now(),
                    'health_check_message' => $errorMessage,
                ]);
                return false;
            }
        } catch (\Exception $e) {
            $errorMessage = 'Health check failed: ' . $e->getMessage();
            
            // Log the health check exception
            \Log::error('Server health check exception', [
                'server_id' => $this->id,
                'hostname' => $this->hostname,
                'url' => $url ?? "{$protocol}://{$this->getHostWithPort()}/health",
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            $this->update([
                'health_status' => 'unhealthy',
                'last_health_check' => now(),
                'health_check_message' => $errorMessage,
            ]);
            return false;
        }
    }

    /**
     * Check if server health check is recent
     */
    public function hasRecentHealthCheck(): bool
    {
        if (!$this->last_health_check) {
            return false;
        }
        
        // Consider health check stale after 2 minutes
        return $this->last_health_check->gt(now()->subMinutes(2));
    }
}