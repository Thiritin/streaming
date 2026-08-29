<?php

namespace App\Services;

use App\Enum\ServerStatusEnum;
use App\Enum\ServerTypeEnum;
use App\Models\Server;
use App\Support\ServerCredentials;
use Illuminate\Support\Facades\View;

class ServerProvisioningService
{
    /**
     * Generate install script for a server using Blade templates.
     *
     * Only the hashes are stored, so the plaintext is whatever this request happens to
     * hold: the pair just minted for a box about to boot, or the pair the operator's own
     * rotate put on their session. With neither, the script renders without credentials
     * and refuses to run rather than installing a box that cannot check in.
     */
    public function generateInstallScript(Server $server): string
    {
        $credentials = $this->credentials($server);

        return View::make('server-provisioning.install-script', [
            'server' => $server,
            'serverUrl' => config('app.url'),
            'sharedSecret' => $credentials?->sharedSecret,
            'deployToken' => $credentials?->deployToken,
        ])->render();
    }

    /**
     * Generate cloud-init script for automated server deployment
     */
    public function generateCloudInit(Server $server): string
    {
        $serverUrl = config('app.url');
        $sharedSecret = $this->credentials($server)?->sharedSecret ?? '';
        $serverId = $server->id;

        // The credential goes in a header, never in the URL: cloud-init writes its whole
        // runcmd to /var/log/streaming-install.log, and the query string would have been
        // in the app's access logs on top of that. No -L either - a redirect is how a
        // header gets replayed to another host.
        $cloudInit = <<<YAML
#cloud-config
package_upgrade: true
packages:
  - curl
  - wget
  - htop
  - net-tools

runcmd:
  - curl -fsS -H 'X-Shared-Secret: {$sharedSecret}' -H 'Accept: application/json' '{$serverUrl}/api/server/{$serverId}/scripts/install' -o /opt/install.sh
  - chmod +x /opt/install.sh
  - /opt/install.sh > /var/log/streaming-install.log 2>&1

final_message: "Streaming server setup completed after \$UPTIME seconds"
YAML;

        return $cloudInit;
    }

    /**
     * Generate specific configuration file using templates
     */
    public function generateConfig(Server $server, string $type): string
    {
        $serverUrl = config('app.url');

        // The edge token verifier and its Dockerfile are shipped verbatim from
        // docker/edge-nginx so there is a single source of truth: the file the
        // edges run is the same file that is tested here.
        if ($type === 'hls-auth-js') {
            return $this->edgeFile('hls-auth.js');
        }

        if ($type === 'edge-dockerfile') {
            return $this->edgeFile('Dockerfile');
        }

        $viewName = match ($type) {
            'docker-compose' => "server-provisioning.{$server->type->value}.docker-compose",
            'nginx' => "server-provisioning.{$server->type->value}.nginx-config",
            'caddy' => "server-provisioning.{$server->type->value}.caddyfile",
            'srs' => 'server-provisioning.origin.srs-config',
            default => null,
        };

        if (! $viewName || ! View::exists($viewName)) {
            return '';
        }

        // Get origin server for edge configs (only active ones)
        $originServer = null;
        if ($server->type->value === 'edge') {
            $originServer = Server::where('type', ServerTypeEnum::ORIGIN)
                ->where('status', ServerStatusEnum::ACTIVE)
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
            $nginxUpstream = 'http://'.$nginxUpstreamHost.':'.$nginxUpstreamPort;
        }

        // For edge server, we need to connect to origin. With no origin on
        // record, fall back to origin.<dns zone> so the config is still valid.
        $originHost = $originServer
            ? $originServer->hostname
            : trim('origin.'.config('dns.zone'), '.');
        // For nginx upstream block - just hostname:port, no protocol
        $originUpstream = $originHost.':443';

        // Determine if we can use internal networking
        $useInternalNetwork = false;
        $originInternalUpstream = null;

        if ($server->type->value === 'edge' && $originServer) {
            // Check if both servers are Hetzner servers with internal IPs
            if ($server->canUseInternalNetworkWith($originServer)) {
                $useInternalNetwork = true;
                // Internal network uses HTTPS on port 443 to Caddy (using internal IP)
                $originInternalUpstream = $originServer->internal_ip.':443';
            }
        }

        return View::make($viewName, [
            'server' => $server,
            'serverUrl' => $serverUrl,
            'nginxUpstream' => $nginxUpstream,
            'originUpstream' => $originUpstream,
            'originServer' => $originServer,
            'useInternalNetwork' => $useInternalNetwork,
            'originInternalUpstream' => $originInternalUpstream,
            // Edges verify playback tokens locally, so they need the same
            // secrets and the same expiry leeway as the app.
            'hlsViewerSecret' => config('stream.token.viewer_secret') ?? '',
            'hlsEmbedSecret' => config('stream.token.embed_secret') ?? '',
            'hlsTokenLeeway' => (int) config('stream.token.leeway'),
            'systemStreamkey' => config('stream.system_streamkey') ?? '',
        ])->render();
    }

    /**
     * The plaintext for this render, if this request holds any.
     */
    private function credentials(Server $server): ?ServerCredentials
    {
        return $server->issuedCredentials ?? ServerCredentials::recall($server);
    }

    /**
     * Read a file that edges run unmodified out of docker/edge-nginx.
     */
    private function edgeFile(string $name): string
    {
        $path = base_path("docker/edge-nginx/{$name}");

        return is_readable($path) ? (string) file_get_contents($path) : '';
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
