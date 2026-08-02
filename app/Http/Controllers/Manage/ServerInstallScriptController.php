<?php

namespace App\Http\Controllers\Manage;

use App\Enum\ServerTypeEnum;
use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Services\ServerProvisioningService;
use App\Support\Manage\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The provisioning scripts and configs for one server, as a set of tabs.
 *
 * The Filament page regex-scraped `cat > x <<'EOF'` blocks back out of the generated
 * shell script to fill its tabs. Each config is asked for directly instead, which is what
 * the service already exposes. The FFmpeg tabs, which only ever rendered a
 * "# not available in current implementation" comment, are gone.
 * See docs/admin/rebuild-plan.md 2.9.
 */
class ServerInstallScriptController extends Controller
{
    public function show(Server $server, ServerProvisioningService $provisioning)
    {
        $this->authorize('viewInstallScript', $server);

        return inertia('Manage/Servers/InstallScript', [
            'server' => [
                'id' => $server->id,
                'hostname' => $server->hostname,
                'type' => $server->type?->value,
            ],
            'tabs' => $this->tabs($server, $provisioning),
            'downloadUrl' => route('manage.servers.install-script.download', $server),
            'regenerateUrl' => route('manage.servers.install-script.regenerate', $server),
        ]);
    }

    public function download(Server $server, ServerProvisioningService $provisioning): StreamedResponse
    {
        $this->authorize('viewInstallScript', $server);

        $script = $provisioning->generateInstallScript($server);

        return response()->streamDownload(
            fn () => print ($script),
            "ef-streaming-install-{$server->id}.sh",
            ['Content-Type' => 'text/x-shellscript'],
        );
    }

    /**
     * Backfills a missing shared secret and re-renders. The scripts are generated on every
     * request, so there is nothing else to invalidate.
     */
    public function regenerate(Server $server): RedirectResponse
    {
        $this->authorize('viewInstallScript', $server);

        if (! $server->shared_secret) {
            $server->update(['shared_secret' => Str::random(40)]);
        }

        Toast::flashSuccess('Scripts Regenerated');

        return back();
    }

    /**
     * @return array<int, array{key: string, label: string, language: string, filename: string, content: string}>
     */
    private function tabs(Server $server, ServerProvisioningService $provisioning): array
    {
        $isOrigin = $server->type === ServerTypeEnum::ORIGIN;

        $tabs = [
            [
                'key' => 'install',
                'label' => 'Install script',
                'language' => 'bash',
                'filename' => "ef-streaming-install-{$server->id}.sh",
                'content' => $provisioning->generateInstallScript($server),
            ],
            [
                'key' => 'cloud-init',
                'label' => 'Cloud-init',
                'language' => 'yaml',
                'filename' => 'cloud-init.yaml',
                'content' => $provisioning->generateCloudInit($server),
            ],
            [
                'key' => 'docker-compose',
                'label' => 'Docker Compose',
                'language' => 'yaml',
                'filename' => 'docker-compose.yml',
                'content' => $provisioning->generateConfig($server, 'docker-compose'),
            ],
            [
                'key' => 'nginx',
                'label' => $isOrigin ? 'nginx (origin)' : 'nginx (edge)',
                'language' => 'nginx',
                'filename' => 'nginx.conf',
                'content' => $provisioning->generateConfig($server, 'nginx'),
            ],
            [
                'key' => 'caddy',
                'label' => $isOrigin ? 'Caddyfile (origin)' : 'Caddyfile (edge)',
                'language' => 'caddy',
                'filename' => 'Caddyfile',
                'content' => $provisioning->generateConfig($server, 'caddy'),
            ],
        ];

        if ($isOrigin) {
            $tabs[] = [
                'key' => 'srs',
                'label' => 'SRS',
                'language' => 'conf',
                'filename' => 'srs.conf',
                'content' => $provisioning->generateConfig($server, 'srs'),
            ];
        } else {
            // Edges verify playback tokens themselves, which needs the njs
            // module in the image and the verifier alongside nginx.conf.
            $tabs[] = [
                'key' => 'edge-dockerfile',
                'label' => 'Dockerfile (edge nginx)',
                'language' => 'docker',
                'filename' => 'Dockerfile.edge-nginx',
                'content' => $provisioning->generateConfig($server, 'edge-dockerfile'),
            ];

            $tabs[] = [
                'key' => 'hls-auth-js',
                'label' => 'Token verifier (njs)',
                'language' => 'javascript',
                'filename' => 'hls-auth.js',
                'content' => $provisioning->generateConfig($server, 'hls-auth-js'),
            ];
        }

        // A template that renders empty would otherwise show as a blank tab with a copy
        // button, which reads as a bug rather than as "not applicable here".
        return array_values(array_filter($tabs, fn (array $tab) => trim($tab['content']) !== ''));
    }
}
