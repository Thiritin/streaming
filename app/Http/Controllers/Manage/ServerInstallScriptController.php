<?php

namespace App\Http\Controllers\Manage;

use App\Enum\ServerTypeEnum;
use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Services\ServerProvisioningService;
use App\Support\Manage\Toast;
use App\Support\ServerCredentials;
use Illuminate\Http\RedirectResponse;
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

        // A row that has never held a credential gets one now: there is nothing to
        // invalidate, and the alternative is a page whose every script refuses to run.
        if (! $server->shared_secret_hash) {
            $server->issueCredentials();
        }

        return inertia('Manage/Servers/InstallScript', [
            'server' => [
                'id' => $server->id,
                'hostname' => $server->hostname,
                'type' => $server->type?->value,
            ],
            'tabs' => $this->tabs($server, $provisioning),
            'hasCredentials' => $this->credentials($server) !== null,
            'rotatedAt' => $server->shared_secret_rotated_at?->diffForHumans(),
            'downloadUrl' => route('manage.servers.install-script.download', $server),
            'rotateUrl' => route('manage.servers.install-script.rotate', $server),
        ]);
    }

    public function download(Server $server, ServerProvisioningService $provisioning): StreamedResponse
    {
        $this->authorize('viewInstallScript', $server);

        $script = $provisioning->generateInstallScript($server);

        return response()->streamDownload(
            fn () => print ($script),
            "install-{$server->id}.sh",
            ['Content-Type' => 'text/x-shellscript'],
        );
    }

    /**
     * Mint a new pair of credentials and re-render the scripts around them.
     *
     * Only the hashes are stored, so this is the only way to get a script carrying
     * plaintext for a server whose credentials were issued in some earlier session. It
     * is destructive by design: the old pair stops being accepted the moment this runs,
     * so a box already installed with it stops checking in until it is reinstalled.
     */
    public function rotate(Server $server): RedirectResponse
    {
        $this->authorize('rotateCredentials', $server);

        $server->issueCredentials();

        Toast::flashSuccess(
            'Credentials rotated',
            'The previous pair is no longer accepted. Reinstall this server with the new script.',
        );

        return back();
    }

    /**
     * The plaintext this request may show, if any.
     */
    private function credentials(Server $server): ?ServerCredentials
    {
        return $server->issuedCredentials ?? ServerCredentials::recall($server);
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
                'filename' => "install-{$server->id}.sh",
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
