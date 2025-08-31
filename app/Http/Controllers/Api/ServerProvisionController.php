<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Server;
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
     * Get configuration file for server
     */
    public function config(Request $request, string $type)
    {
        $sharedSecret = $request->header('X-Shared-Secret') ?: $request->query('shared_secret');

        // Find server by shared secret
        $server = Server::where('shared_secret', $sharedSecret)->first();
        if (!$server) {
            return response('Unauthorized', 401);
        }

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
                
            default:
                return response('Not found', 404);
        }

        return response($content, 200)
            ->header('Content-Type', $contentType);
    }

    /**
     * Get script file for server
     */
    public function script(Request $request, string $script)
    {
        $sharedSecret = $request->header('X-Shared-Secret') ?: $request->query('shared_secret');

        // Find server by shared secret
        $server = Server::where('shared_secret', $sharedSecret)->first();
        if (!$server) {
            return response('Unauthorized', 401);
        }

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
     * Register server after installation
     */
    public function register(Request $request)
    {
        $sharedSecret = $request->header('X-Shared-Secret');

        $data = $request->validate([
            'server_id' => 'required|exists:servers,id',
            'hostname' => 'required|string',
            'ip' => 'required|ip',
            'status' => 'required|string',
        ]);

        $server = Server::find($data['server_id']);
        if (!$server || $server->shared_secret !== $sharedSecret) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        // Check if this server can become origin (if it's an origin type)
        if ($server->type === \App\Enum\ServerTypeEnum::ORIGIN && 
            $data['status'] === \App\Enum\ServerStatusEnum::ACTIVE->value &&
            !$server->canBecomeOrigin()) {
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

        // Add edge-specific config if needed
        if ($server->type === \App\Enum\ServerTypeEnum::EDGE) {
            // Get the current origin server URL
            $origin = Server::getOrigin();
            if ($origin) {
                $updateData['origin_url'] = $origin->getHlsBaseUrl();
            }
        }

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
        $sharedSecret = $request->header('X-Shared-Secret');

        if ($server->shared_secret !== $sharedSecret) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $updateData = [
            'last_heartbeat' => now(),
        ];

        // Handle viewer count for edge servers
        if ($server->type === \App\Enum\ServerTypeEnum::EDGE && $request->has('viewer_count')) {
            $updateData['viewer_count'] = $request->input('viewer_count', 0);
        }

        // Update server
        $server->update($updateData);

        // Optional: Include health data in request
        $health = $request->input('health', []);
        if (!empty($health)) {
            $server->update([
                'metadata' => array_merge($server->metadata ?? [], [
                    'health' => $health,
                    'health_updated_at' => now(),
                ]),
            ]);
        }

        return response()->json([
            'status' => 'ok',
            'type' => $server->type->value,
            'total_viewers' => $server->type === \App\Enum\ServerTypeEnum::EDGE ? Server::getTotalViewers() : null,
        ]);
    }
}
