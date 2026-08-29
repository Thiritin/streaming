<?php

namespace App\Http\Controllers\Api;

use App\Enum\ServerStatusEnum;
use App\Enum\ServerTypeEnum;
use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Models\ServerMetric;
use App\Services\ServerProvisioningService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ServerProvisionController extends Controller
{
    protected ServerProvisioningService $provisioningService;

    public function __construct(ServerProvisioningService $provisioningService)
    {
        $this->provisioningService = $provisioningService;
    }

    /**
     * Get configuration file for server.
     *
     * The server comes from the path and CheckSharedSecretMiddleware has already checked
     * the presented credential against that row, so a box can only ever fetch its own
     * config. It used to be found by the secret instead, which let any edge ask for the
     * origin's - and the origin's carries the DVR credentials.
     */
    public function config(Server $server, string $type)
    {
        $content = '';
        $contentType = 'text/plain';

        switch ($type) {
            case 'nginx-origin':
                $content = $this->provisioningService->generateNginxOriginConfig($server);
                break;

            case 'nginx-edge':
                $content = $this->provisioningService->generateNginxEdgeConfig($server);
                break;

            case 'caddy-origin':
                $content = $this->provisioningService->generateCaddyOriginConfig($server);
                break;

            case 'caddy-edge':
                $content = $this->provisioningService->generateCaddyEdgeConfig($server);
                break;

            case 'srs':
            case 'srs-origin':
                $content = $this->provisioningService->generateSrsConfig($server);
                break;

            case 'docker-compose':
                $content = $this->provisioningService->generateDockerCompose($server);
                $contentType = 'application/x-yaml';
                break;

                // Edge nginx needs the njs module to verify playback tokens locally,
                // so it is built on the host from this Dockerfile rather than pulled.
            case 'dockerfile-edge':
                $content = $this->provisioningService->generateConfig($server, 'edge-dockerfile');
                break;

            case 'hls-auth-js':
                $content = $this->provisioningService->generateConfig($server, 'hls-auth-js');
                $contentType = 'application/javascript';
                break;

            default:
                return response('Not found', 404);
        }

        return response($content, 200)
            ->header('Content-Type', $contentType);
    }

    /**
     * Get script file for server
     */
    public function script(Server $server, string $script)
    {
        $content = '';

        switch ($script) {
            case 'hls-tracker':
                $content = file_get_contents(base_path('scripts/hls_session_tracker.py'));
                break;

            case 'install':
                $content = $this->provisioningService->generateInstallScript($server);
                break;

            default:
                return response('Not found', 404);
        }

        return response($content, 200)
            ->header('Content-Type', 'text/plain');
    }

    /**
     * Register server after installation.
     *
     * `server_id` is gone from the body: the identity is the path, and a body field
     * naming a different row than the credential belongs to is exactly the confusion
     * this endpoint used to allow.
     */
    public function register(Request $request, Server $server)
    {
        $data = $request->validate([
            'hostname' => 'required|string',
            'ip' => 'required|ip',
            'status' => 'required|string',
        ]);

        // Check if this server can become origin (if it's an origin type)
        if ($server->type === ServerTypeEnum::ORIGIN &&
            $data['status'] === ServerStatusEnum::ACTIVE->value &&
            ! $server->canBecomeOrigin()) {
            Log::warning('Cannot activate origin server - another origin is already active', [
                'server_id' => $server->id,
                'hostname' => $data['hostname'],
            ]);

            return response()->json([
                'error' => 'Another origin server is already active',
                'status' => 'conflict',
            ], 409);
        }

        // Update server information
        $updateData = [
            'hostname' => $data['hostname'],
            'ip' => $data['ip'],
            'status' => $data['status'],
            'last_heartbeat' => now(),
        ];

        // Edge servers will dynamically get origin URL when needed

        $server->update($updateData);

        Log::info('Server registered', [
            'server_id' => $server->id,
            'hostname' => $data['hostname'],
            'ip' => $data['ip'],
            'type' => $server->type->value,
        ]);

        return response()->json([
            'status' => 'ok',
            'type' => $server->type->value,
        ]);
    }

    /**
     * Receive heartbeat from server
     */
    public function heartbeat(Request $request, Server $server)
    {
        $updateData = [
            'last_heartbeat' => now(),
        ];

        // Handle viewer count for edge servers
        if ($server->type === ServerTypeEnum::EDGE && $request->has('viewer_count')) {
            $updateData['viewer_count'] = $request->input('viewer_count', 0);
        }

        // Update server
        $server->update($updateData);

        // Optional: Include health data in request
        $health = $request->input('health', []);
        if (! empty($health)) {
            $server->update([
                'metadata' => array_merge($server->metadata ?? [], [
                    'health' => $health,
                    'health_updated_at' => now(),
                ]),
            ]);
        }

        $this->recordMetrics($server, $request->input('metrics'));

        return response()->json([
            'status' => 'ok',
            'type' => $server->type->value,
            'total_viewers' => $server->type === ServerTypeEnum::EDGE ? Server::getTotalViewers() : null,
        ]);
    }

    /**
     * Store the system sample that came with a heartbeat.
     *
     * Every field is optional. The box sends rates it worked out from its own previous
     * sample, so the first heartbeat after an install - and the first after a reboot -
     * legitimately has no network or CPU figure, and a missing field must not cost us
     * the rest of the row. Anything it does send is clamped to a sane range, since a
     * counter wrap on the box would otherwise draw a spike nobody can read past.
     */
    private function recordMetrics(Server $server, mixed $metrics): void
    {
        if (! is_array($metrics) || empty($metrics)) {
            return;
        }

        $number = function (string $key, ?float $max = null) use ($metrics): ?float {
            $value = $metrics[$key] ?? null;

            if (! is_numeric($value)) {
                return null;
            }

            $value = (float) $value;

            if ($value < 0 || ($max !== null && $value > $max)) {
                return null;
            }

            return $value;
        };

        $integer = fn (string $key, ?float $max = null) => ($value = $number($key, $max)) === null
            ? null
            : (int) round($value);

        ServerMetric::create([
            'server_id' => $server->id,
            'recorded_at' => now(),
            'cpu_percent' => $number('cpu_percent', 100),
            'load_1' => $number('load_1', 10000),
            'cpu_cores' => $integer('cpu_cores', 1024),
            'memory_used_bytes' => $integer('memory_used_bytes'),
            'memory_total_bytes' => $integer('memory_total_bytes'),
            'disk_used_bytes' => $integer('disk_used_bytes'),
            'disk_total_bytes' => $integer('disk_total_bytes'),
            // 100 Gbit/s in bytes, well above anything Hetzner will hand us and low
            // enough to catch a wrapped counter.
            'net_rx_bytes_per_sec' => $integer('net_rx_bytes_per_sec', 12_500_000_000),
            'net_tx_bytes_per_sec' => $integer('net_tx_bytes_per_sec', 12_500_000_000),
            'uptime_seconds' => $integer('uptime_seconds'),
            // The app's own count, not the box's, so the viewers line on the server
            // page is the same number the rest of /manage shows.
            'viewer_count' => $server->type === ServerTypeEnum::EDGE ? (int) $server->viewer_count : null,
        ]);
    }
}
