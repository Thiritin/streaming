<?php

namespace App\Services;

use App\Models\Server;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;

class ServerProvisioningService
{
    /**
     * Generate install script for a server using Blade templates
     */
    public function generateInstallScript(Server $server): string
    {
        $serverUrl = config('app.url');
        $sharedSecret = $server->shared_secret ?: Str::random(32);

        // Update server with shared secret if not set
        if (!$server->shared_secret) {
            $server->update(['shared_secret' => $sharedSecret]);
        }

        return View::make('server-provisioning.install-script', [
            'server' => $server,
            'serverUrl' => $serverUrl,
            'sharedSecret' => $sharedSecret,
        ])->render();
    }

    /**
     * Generate cloud-init script for automated server deployment
     */
    public function generateCloudInit(Server $server): string
    {
        $serverUrl = config('app.url');
        $sharedSecret = $server->shared_secret ?: Str::random(32);
        
        // Update server with shared secret if not set
        if (!$server->shared_secret) {
            $server->update(['shared_secret' => $sharedSecret]);
        }
        
        // Simple cloud-init that just downloads and runs the install script
        $cloudInit = <<<YAML
#cloud-config
package_upgrade: true
packages:
  - curl
  - wget
  - htop
  - net-tools

runcmd:
  - curl -fsSL '{$serverUrl}/api/server/scripts/install?shared_secret={$sharedSecret}' -o /opt/install.sh
  - chmod +x /opt/install.sh
  - /opt/install.sh > /var/log/ef-streaming-install.log 2>&1

final_message: "EF Streaming server setup completed after \$UPTIME seconds"
YAML;

        return $cloudInit;
    }

    /**
     * Generate specific configuration file using templates
     */
    public function generateConfig(Server $server, string $type): string
    {
        $serverUrl = config('app.url');
        $sharedSecret = $server->shared_secret ?: Str::random(32);

        $viewName = match($type) {
            'docker-compose' => "server-provisioning.{$server->type->value}.docker-compose",
            'nginx' => "server-provisioning.{$server->type->value}.nginx-config",
            'caddy' => "server-provisioning.{$server->type->value}.caddyfile",
            'srs' => "server-provisioning.origin.srs-config",
            default => null,
        };

        if (!$viewName || !View::exists($viewName)) {
            return '';
        }

        // Get origin server for edge configs (only active ones)
        $originServer = null;
        if ($server->type->value === 'edge') {
            $originServer = Server::where('type', \App\Enum\ServerTypeEnum::ORIGIN)
                ->where('status', \App\Enum\ServerStatusEnum::ACTIVE)
                ->first();
        }

        // Parse the server URL for nginx upstream
        $parsedUrl = parse_url($serverUrl);
        $nginxUpstreamHost = $parsedUrl['host'] ?? 'localhost';
        $nginxUpstreamScheme = $parsedUrl['scheme'] ?? 'http';
        
        // For HTTPS, use the URL directly without port. For HTTP, use host:port
        if ($nginxUpstreamScheme === 'https') {
            $nginxUpstream = $serverUrl; // Use full HTTPS URL
        } else {
            $nginxUpstreamPort = $parsedUrl['port'] ?? 80;
            $nginxUpstream = 'http://' . $nginxUpstreamHost . ':' . $nginxUpstreamPort;
        }

        // For edge server, we need to connect to origin
        // Use a sensible default if no origin server is found
        $originHost = $originServer ? $originServer->hostname : 'origin.stream.eurofurence.org';
        // For nginx upstream block - just hostname:port, no protocol
        $originUpstream = $originHost . ':443';
        
        // Determine if we can use internal networking
        $useInternalNetwork = false;
        $originInternalUpstream = null;
        
        if ($server->type->value === 'edge' && $originServer) {
            // Check if both servers are Hetzner servers with internal IPs
            if ($server->canUseInternalNetworkWith($originServer)) {
                $useInternalNetwork = true;
                // Internal network uses HTTP on port 80 directly to nginx
                $originInternalUpstream = $originServer->internal_ip . ':80';
            }
        }

        return View::make($viewName, [
            'server' => $server,
            'serverUrl' => $serverUrl,
            'sharedSecret' => $sharedSecret,
            'nginxUpstream' => $nginxUpstream,
            'originUpstream' => $originUpstream,
            'originServer' => $originServer,
            'useInternalNetwork' => $useInternalNetwork,
            'originInternalUpstream' => $originInternalUpstream,
        ])->render();
    }

    // Legacy methods for backward compatibility
    public function generateDockerCompose(Server $server): string
    {
        return $this->generateConfig($server, 'docker-compose');
    }

    public function generateNginxOriginConfig(Server $server): string
    {
        return $this->generateConfig($server, 'nginx');
    }

    public function generateNginxEdgeConfig(Server $server): string
    {
        return $this->generateConfig($server, 'nginx');
    }

    public function generateCaddyOriginConfig(Server $server): string
    {
        return $this->generateConfig($server, 'caddy');
    }

    public function generateCaddyEdgeConfig(Server $server): string
    {
        return $this->generateConfig($server, 'caddy');
    }

    public function generateSrsConfig(Server $server): string
    {
        return $this->generateConfig($server, 'srs');
    }
}