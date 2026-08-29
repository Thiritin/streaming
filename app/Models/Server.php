<?php

namespace App\Models;

use App\Enum\ServerStatusEnum;
use App\Enum\ServerTypeEnum;
use App\Enum\SourceStatusEnum;
use App\Jobs\Server\DeleteServerJob;
use App\Jobs\Server\Deprovision\RemovalConditionCheckerJob;
use App\Support\ServerCredentials;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class Server extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'status' => ServerStatusEnum::class,
        'type' => ServerTypeEnum::class,
        'port' => 'integer',
        'max_clients' => 'integer',
        'viewer_count' => 'integer',
        'last_heartbeat' => 'datetime',
        'last_health_check' => 'datetime',
        'shared_secret_rotated_at' => 'datetime',
        'deploy_token_rotated_at' => 'datetime',
        'credential_rejected_at' => 'datetime',
        'immutable' => 'boolean',
        // What the server reports about itself on heartbeat.
        'metadata' => 'array',
    ];

    /**
     * The hashes are not the plaintext, but they are still the thing every comparison
     * runs against, so they stay out of anything that serialises a row.
     */
    protected $hidden = [
        'shared_secret_hash',
        'deploy_token_hash',
    ];

    /**
     * The plaintext minted during this request, for the one render that may show it.
     *
     * Deliberately a plain property rather than an attribute: it must not be written
     * back to the row, serialised into a job payload or reach `toArray()`.
     */
    public ?ServerCredentials $issuedCredentials = null;

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
            // Every row holds a credential from the moment it exists, so nothing has to
            // cope with a server that cannot authenticate at all. Each half is filled
            // only if it is missing: a row created with one already set keeps it.
            $server->fillMissingCredentials();

            // Set default status if not provided
            if (empty($server->status)) {
                $server->status = ServerStatusEnum::PROVISIONING;
            }
        });

        // The row has an id by now, which is what the plaintext is parked under. Only a
        // row that minted both halves has anything worth showing.
        static::created(function ($server) {
            if ($server->issuedCredentials) {
                ServerCredentials::remember($server, $server->issuedCredentials);
            }
        });

        // The playlist path reads the active edges out of the cache rather than the
        // database (HlsController::activeEdges), so a deprovisioned edge has to be
        // taken out of that list the moment it changes - otherwise viewers keep being
        // pinned to a box that is going away.
        $forgetEdgeCaches = function ($server) {
            Cache::forget('hls_active_edges');

            if ($server->hostname) {
                Cache::forget('hls_local_edge:'.$server->hostname);
            }
        };

        static::saved($forgetEdgeCaches);
        static::deleted($forgetEdgeCaches);
    }

    /**
     * Fill whichever half is not set yet, leaving anything already there alone.
     */
    public function fillMissingCredentials(): void
    {
        $credentials = ServerCredentials::mint();
        $mintedBoth = true;

        if (empty($this->shared_secret_hash)) {
            $this->shared_secret_hash = static::hashCredential($credentials->sharedSecret);
            $this->shared_secret_rotated_at = now();
        } else {
            $mintedBoth = false;
        }

        if (empty($this->deploy_token_hash)) {
            $this->deploy_token_hash = static::hashCredential($credentials->deployToken);
            $this->deploy_token_rotated_at = now();
        } else {
            $mintedBoth = false;
        }

        if ($mintedBoth) {
            $this->issuedCredentials = $credentials;
        }
    }

    /**
     * Mint a fresh pair on this instance without saving.
     */
    public function mintCredentials(): ServerCredentials
    {
        $credentials = ServerCredentials::mint();

        $this->shared_secret_hash = static::hashCredential($credentials->sharedSecret);
        $this->shared_secret_rotated_at = now();
        $this->deploy_token_hash = static::hashCredential($credentials->deployToken);
        $this->deploy_token_rotated_at = now();

        return $this->issuedCredentials = $credentials;
    }

    /**
     * Mint and save. The previous pair stops working the moment this returns - there is
     * no window in which both are accepted, so a box has to be reinstalled with the new
     * one rather than left running on the old.
     */
    public function issueCredentials(): ServerCredentials
    {
        $credentials = $this->mintCredentials();

        $this->save();

        ServerCredentials::remember($this, $credentials);

        return $credentials;
    }

    public static function hashCredential(string $plaintext): string
    {
        return hash('sha256', $plaintext);
    }

    public function verifySharedSecret(mixed $presented): bool
    {
        return $this->credentialMatches($this->shared_secret_hash, $presented);
    }

    /**
     * Deploy authority, separate from the heartbeat's so the two can be revoked apart.
     */
    public function verifyDeployToken(mixed $presented): bool
    {
        return $this->credentialMatches($this->deploy_token_hash, $presented);
    }

    /**
     * The one lookup left that goes by credential rather than by identity, because an
     * SRS callback names no server. It matches the stored digest, so the column is an
     * index lookup rather than a timing oracle over the secret itself.
     */
    public static function activeByPresentedSecret(mixed $presented): ?self
    {
        if (! is_string($presented) || $presented === '') {
            return null;
        }

        return static::where('shared_secret_hash', static::hashCredential($presented))
            ->where('status', ServerStatusEnum::ACTIVE)
            ->first();
    }

    /**
     * Stamp the first refusal, so a box that is being turned away is distinguishable
     * from one that has crashed. Later refusals leave the first timestamp alone: it is
     * when this started, not when it last happened.
     */
    public function recordCredentialRejection(): void
    {
        if ($this->credential_rejected_at) {
            return;
        }

        $this->credential_rejected_at = now();

        $this->writeCredentialRejection($this->credential_rejected_at);
    }

    public function clearCredentialRejection(): void
    {
        if (! $this->credential_rejected_at) {
            return;
        }

        $this->credential_rejected_at = null;

        $this->writeCredentialRejection(null);
    }

    /**
     * Straight to the table. A refused credential is not an edit to the server: it must
     * not move `updated_at`, and it must not wake the saved hook that drops the edge
     * caches - a box hammering a wrong credential would otherwise clear `hls_active_edges`
     * once a minute for every viewer on the site.
     */
    private function writeCredentialRejection(mixed $at): void
    {
        $this->getConnection()
            ->table($this->getTable())
            ->where('id', $this->getKey())
            ->update(['credential_rejected_at' => $at]);
    }

    /**
     * Fail closed: an unset column, an empty column, and an empty or non-string
     * presented value are all "no", and the only way through is hash_equals.
     */
    private function credentialMatches(mixed $stored, mixed $presented): bool
    {
        if (! is_string($stored) || $stored === '' || ! is_string($presented) || $presented === '') {
            return false;
        }

        return hash_equals($stored, static::hashCredential($presented));
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
     * The Hetzner instance size this server is, or should be provisioned as.
     *
     * Falls back to the per-role default for servers created before `server_type`
     * existed, and for anything provisioned outside the /manage action. The size is a
     * money decision - Hetzner bills hourly - so it is config plus a per-server
     * override rather than a constant in the provisioning job.
     */
    public function hetznerServerType(): string
    {
        if ($this->server_type) {
            return $this->server_type;
        }

        $role = $this->type === ServerTypeEnum::ORIGIN ? 'origin' : 'edge';

        return config("stream.server.defaults.{$role}")
            ?? ($role === 'origin' ? 'ccx33' : 'cpx21');
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

        return ! $existingOrigin;
    }

    /**
     * Get the HLS base URL for this server
     */
    public function getHlsBaseUrl(): string
    {
        if ($this->type === ServerTypeEnum::ORIGIN) {
            // Origin server serves HLS directly from SRS
            $protocol = $this->port === 443 ? 'https' : 'http';
            $port = in_array($this->port, [80, 443]) ? '' : ':'.$this->port;

            return "{$protocol}://{$this->hostname}{$port}";
        } else {
            // Edge server proxies from origin
            $protocol = $this->port === 443 ? 'https' : 'http';
            $port = in_array($this->port, [80, 443]) ? '' : ':'.$this->port;

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
        if (! $this->last_heartbeat) {
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

    /**
     * Viewer sessions that were served by this edge. There is no `users()` counterpart:
     * an assignment belongs to a viewing session, not to an account.
     */
    public function sessions()
    {
        return $this->hasMany(SourceUser::class);
    }

    /**
     * What the box reported about itself, one row a minute. See ServerMetric.
     */
    public function metrics()
    {
        return $this->hasMany(ServerMetric::class);
    }

    public function latestMetric(): ?ServerMetric
    {
        return $this->metrics()->latest('recorded_at')->first();
    }

    public function deprovision()
    {
        // Allow deprovisioning any server without restrictions
        DeleteServerJob::dispatch($this);
    }

    /**
     * Delete the cloud resources for a teardown that did not finish.
     *
     * Skips straight past taking the edge out of rotation, which a stalled row has
     * already had done to it, and re-runs the half that talks to Hetzner and DNS.
     * RemovalConditionCheckerJob still refuses to act on a server that has been
     * reactivated since, so this cannot delete a live edge by mistake.
     */
    public function forceDeprovision(): void
    {
        RemovalConditionCheckerJob::dispatch($this);
    }

    /**
     * Override delete to release viewers first
     */
    public function delete()
    {
        // Release any session still pinned here, so the next playlist request re-picks.
        $this->sessions()->whereNull('left_at')->update(['server_id' => null]);

        return parent::delete();
    }

    /**
     * Check if this is a Hetzner cloud server
     */
    public function isHetznerServer(): bool
    {
        return ! empty($this->hetzner_id);
    }

    /**
     * Check if this server can use internal networking with another server
     */
    public function canUseInternalNetworkWith(?Server $otherServer): bool
    {
        if (! $otherServer) {
            return false;
        }

        // Both servers must be Hetzner servers
        if (! $this->isHetznerServer() || ! $otherServer->isHetznerServer()) {
            return false;
        }

        // Both servers must have internal IPs
        if (empty($this->internal_ip) || empty($otherServer->internal_ip)) {
            return false;
        }

        return true;
    }

    /**
     * Whether the box is serving yet.
     *
     * Every part of this used to be wrong, and it failed silently because the only
     * caller retries and then gives up:
     *
     *   - It asked for `/ready`. Nothing serves that. Both nginx configs expose
     *     `/health`, which is the endpoint the install script itself waits on.
     *   - It expected `json('code') === 0`. `/health` answers `healthy` in plain text,
     *     so the check could not have passed even against the right path.
     *   - For origins it targeted `{ip}:1985`, the SRS admin API. That port is not in
     *     the `Origin Server` firewall - correctly, since it can manipulate streams -
     *     so the request could never connect at all.
     *
     * Now one path for both roles: `/health` over 443, which Caddy proxies to nginx and
     * which is the only HTTP port the firewall permits. Plain 2xx is the signal; the
     * body is checked loosely so a future change to its wording does not break
     * provisioning.
     *
     * Note this is a belt to the install script's braces. A finished box registers
     * itself as active over `/api/server/register`, and that is the path servers have
     * actually become available by.
     */
    public function isReady(): bool
    {
        // For manual/local servers (null hetzner_id), assume they're ready if active
        if (! $this->hetzner_id && $this->status === ServerStatusEnum::ACTIVE) {
            return true;
        }

        $url = 'https://'.$this->hostname.'/health';

        // Local Docker containers have no TLS and no public hostname.
        if (! $this->hetzner_id) {
            $url = 'http://'.$this->hostname.':'.$this->port.'/health';
        }

        try {
            $response = Http::timeout(5)->get($url);
        } catch (ClientException|ServerException|ConnectionException $e) {
            return false;
        }

        return $response->successful()
            && str_contains(strtolower($response->body()), 'health');
    }

    public function isInUse(): bool
    {
        if ($this->type === ServerTypeEnum::ORIGIN) {
            // Origin is in use if any streams are live
            return Source::where('status', SourceStatusEnum::ONLINE)->exists();
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

        return $this->hostname.':'.$this->port;
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
            $errorMessage = 'Health check failed: '.$e->getMessage();

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
        if (! $this->last_health_check) {
            return false;
        }

        // Consider health check stale after 2 minutes
        return $this->last_health_check->gt(now()->subMinutes(2));
    }
}
