<?php

namespace App\Http\Controllers\Api;

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
        $serverId = $request->query('server_id');
        $sharedSecret = $request->header('X-Shared-Secret') ?: $request->query('shared_secret');
        
        // Find server by ID and validate shared secret
        $server = Server::find($serverId);
        if (!$server || $server->shared_secret !== $sharedSecret) {
            return response('Unauthorized', 401);
        }
        
        $content = '';
        $contentType = 'text/plain';
        
        switch ($type) {
            case 'nginx':
                $content = file_get_contents(base_path('config/nginx-hls-auth.conf'));
                // Replace placeholders
                $content = str_replace('http://localhost:8000', config('app.url'), $content);
                $content = str_replace('API_KEY=CHANGE_ME_TO_SECURE_KEY', "API_KEY={$sharedSecret}", $content);
                break;
                
            case 'srs':
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
        $serverId = $request->query('server_id');
        $sharedSecret = $request->header('X-Shared-Secret') ?: $request->query('shared_secret');
        
        // Find server by ID and validate shared secret
        $server = Server::find($serverId);
        if (!$server || $server->shared_secret !== $sharedSecret) {
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
        
        // Update server information
        $server->update([
            'hostname' => $data['hostname'],
            'ip' => $data['ip'],
            'status' => $data['status'],
            'last_heartbeat' => now(),
        ]);
        
        Log::info('Server registered', [
            'server_id' => $server->id,
            'hostname' => $data['hostname'],
            'ip' => $data['ip'],
        ]);
        
        return response()->json(['status' => 'ok']);
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
        
        // Update last heartbeat
        $server->update([
            'last_heartbeat' => now(),
        ]);
        
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
        
        return response()->json(['status' => 'ok']);
    }
}