<?php

namespace Tests\Feature;

use App\Enum\ServerStatusEnum;
use App\Enum\ServerTypeEnum;
use App\Models\Server;
use App\Services\PlaybackTokenService;
use App\Services\ServerProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * Guards the edge-side half of playback tokens: the generated nginx config has
 * to actually wire njs in and gate both playlists and segments, and the verifier
 * the edges run has to be the same file the rest of the suite exercises.
 *
 * See docs/streaming-auth-redesign.md.
 */
class EdgeTokenEnforcementTest extends TestCase
{
    use RefreshDatabase;

    private Server $edge;

    private ServerProvisioningService $provisioning;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('stream.token.viewer_secret', str_repeat('a', 64));
        Config::set('stream.token.embed_secret', str_repeat('b', 64));
        Config::set('stream.token.leeway', 60);
        Config::set('stream.system_streamkey', 'system-fixture-key');

        $this->edge = Server::factory()->create([
            'type' => ServerTypeEnum::EDGE,
            'status' => ServerStatusEnum::ACTIVE,
            'shared_secret' => 'edge-shared-secret',
        ]);

        $this->provisioning = app(ServerProvisioningService::class);
    }

    public function test_edge_nginx_loads_the_njs_verifier(): void
    {
        $config = $this->provisioning->generateConfig($this->edge, 'nginx');

        $this->assertStringContainsString('load_module modules/ngx_http_js_module.so;', $config);
        $this->assertStringContainsString('js_import hlsAuth from /etc/nginx/njs/hls-auth.js;', $config);
        $this->assertStringContainsString('js_content hlsAuth.verify;', $config);
    }

    public function test_edge_nginx_exports_the_secrets_to_njs(): void
    {
        // nginx strips the worker environment, so without these njs sees nothing.
        $config = $this->provisioning->generateConfig($this->edge, 'nginx');

        foreach (['HLS_VIEWER_SECRET', 'HLS_EMBED_SECRET', 'HLS_TOKEN_LEEWAY', 'STREAM_SYSTEM_STREAMKEY'] as $name) {
            $this->assertStringContainsString("env {$name};", $config);
        }
    }

    public function test_edge_nginx_authenticates_playlists_as_well_as_segments(): void
    {
        $config = $this->provisioning->generateConfig($this->edge, 'nginx');

        foreach (['m3u8', 'ts'] as $extension) {
            $location = $this->locationBody($config, $extension);

            $this->assertStringContainsString(
                'auth_request /auth;',
                $location,
                "The {$extension} location must be gated; leaving playlists open was the original hole."
            );
        }
    }

    /**
     * Nothing throttles by client address.
     *
     * A convention NATs its whole audience onto one public IP, so any limit keyed on
     * `$binary_remote_addr` is shared by every on-site viewer at once: the old
     * `limit_conn 10` meant ten people, and `limit_req 30r/s` was split between
     * hundreds. At `hls_time 2` one viewer makes about one request a second, so
     * neither ceiling ever shaped real load - they only ever blocked the venue.
     *
     * Re-keying on the playback token was tried and dropped: it needed an njs
     * `js_set` on the request path, which fails at nginx *startup* rather than
     * degrading, and could not be validated before the event.
     *
     * Access control is `auth_request /auth`, asserted above, not throughput limits.
     */
    public function test_the_edge_does_not_throttle_by_client_address(): void
    {
        // Comments only, with the reasoning in them, would otherwise match every one of
        // these strings. Directives are what matter.
        $directives = preg_replace('/^\s*#.*$/m', '', $this->provisioning->generateConfig($this->edge, 'nginx'));

        foreach (['limit_req', 'limit_conn', 'js_set'] as $directive) {
            $this->assertStringNotContainsString(
                $directive,
                $directives,
                "The edge config declares {$directive}. A limit keyed on the client "
                .'address throttles a whole NATed venue as if it were one viewer, and '
                .'js_set was dropped because it fails at nginx startup.'
            );
        }

        // The gate that remains, restated here so removing the limits can never be
        // mistaken for removing access control.
        $this->assertStringContainsString('auth_request /auth;', $directives);
    }

    public function test_no_media_request_can_reach_laravel(): void
    {
        $config = $this->provisioning->generateConfig($this->edge, 'nginx');

        // The streamkey fallback is gone. It was the last thing that put a PHP
        // request in front of a segment, and it could only ever be resolved
        // against the database, so it could not be cached usefully either.
        $this->assertStringNotContainsString('/auth-legacy', $config);
        $this->assertStringNotContainsString('/api/hls/auth', $config);

        // Nothing left to cache auth answers for, either.
        $this->assertStringNotContainsString('auth_cache', $config);
    }

    public function test_edge_compose_builds_the_image_and_passes_the_secrets(): void
    {
        $compose = $this->provisioning->generateConfig($this->edge, 'docker-compose');

        // Pulling nginx:alpine directly would have no njs module.
        $this->assertStringNotContainsString('image: nginx:alpine', $compose);
        $this->assertStringContainsString('dockerfile: Dockerfile.edge-nginx', $compose);
        $this->assertStringContainsString('./hls-auth.js:/etc/nginx/njs/hls-auth.js:ro', $compose);
        $this->assertStringContainsString('HLS_VIEWER_SECRET: '.str_repeat('a', 64), $compose);
        $this->assertStringContainsString('HLS_EMBED_SECRET: '.str_repeat('b', 64), $compose);
        $this->assertStringContainsString('HLS_TOKEN_LEEWAY: 60', $compose);
    }

    public function test_the_verifier_is_served_verbatim_from_the_repository(): void
    {
        // One source of truth: the file edges run is the file that is tested.
        $this->assertSame(
            file_get_contents(base_path('docker/edge-nginx/hls-auth.js')),
            $this->provisioning->generateConfig($this->edge, 'hls-auth-js')
        );

        $this->assertSame(
            file_get_contents(base_path('docker/edge-nginx/Dockerfile')),
            $this->provisioning->generateConfig($this->edge, 'edge-dockerfile')
        );
    }

    public function test_the_verifier_and_the_minter_agree_on_the_wire_format(): void
    {
        $verifier = file_get_contents(base_path('docker/edge-nginx/hls-auth.js'));

        $this->assertStringContainsString(
            "const TOKEN_VERSION = '".PlaybackTokenService::VERSION."';",
            $verifier,
            'The edge verifier and PlaybackTokenService must agree on the token version.'
        );

        // Claim names are the contract between the two implementations.
        foreach (['typ', 'src', 'exp'] as $claim) {
            $this->assertStringContainsString("claims.{$claim}", $verifier);
        }
    }

    public function test_edge_files_are_downloadable_with_the_shared_secret(): void
    {
        foreach (['dockerfile-edge', 'hls-auth-js'] as $type) {
            $this->getJson("/api/server/config/{$type}", ['X-Shared-Secret' => 'edge-shared-secret'])
                ->assertOk();
        }
    }

    public function test_edge_files_are_not_downloadable_without_the_shared_secret(): void
    {
        foreach (['dockerfile-edge', 'hls-auth-js'] as $type) {
            $this->getJson("/api/server/config/{$type}")->assertStatus(401);
            $this->getJson("/api/server/config/{$type}?shared_secret=wrong")->assertStatus(401);
        }
    }

    /**
     * A bad secret must fail, not hand back something that looks like a file.
     *
     * `CheckSharedSecretMiddleware` throws `AuthenticationException`. Under Laravel 12
     * that rendered as a 302 to the login page even on an API route, so `curl -L` in the
     * install script would follow the redirect and write the HTML login page to disk as
     * `hls-auth.js` - a broken edge with no error anywhere. The install script works
     * around it by sending `Accept: application/json` to force a clean 401.
     *
     * Laravel 13 answers 401 directly here, so the hazard is gone at the source and the
     * header is now belt-and-braces rather than load-bearing. Both halves are asserted:
     * the status, and the header that would still save us if this ever regressed.
     */
    public function test_an_unauthenticated_request_fails_rather_than_redirecting(): void
    {
        $this->get('/api/server/config/hls-auth-js')->assertStatus(401);

        $this->assertStringContainsString(
            'Accept: application/json',
            file_get_contents(base_path('resources/views/server-provisioning/install-script.blade.php')),
            'The install script must ask for JSON so a bad secret fails instead of writing HTML.'
        );
    }

    /**
     * Pull out the body of the `location ~ ^/live/(.+\.EXT)$` block.
     */
    private function locationBody(string $config, string $extension): string
    {
        $marker = 'location ~ ^/live/(.+\\.'.$extension.')$ {';
        $start = strpos($config, $marker);

        $this->assertNotFalse($start, "No location block found for .{$extension}");

        // Up to the start of the next location block, which is close enough to
        // the block body for these assertions.
        $next = strpos($config, 'location ', $start + strlen($marker));

        return $next === false
            ? substr($config, $start)
            : substr($config, $start, $next - $start);
    }

    /**
     * Provisioning images must name their registry.
     *
     * The defaults were bare (`ffmpeg-hls:latest`), which Docker resolves as an official
     * Docker Hub image. None exists, so a fresh origin died at `docker compose up` with
     * "pull access denied" and the stack never started - with nothing in the app to say
     * so, because the failure happens on the box.
     */
    public function test_provisioning_images_are_fully_qualified(): void
    {
        foreach (config('stream.images') as $key => $reference) {
            $this->assertStringContainsString(
                '/',
                $reference,
                "stream.images.{$key} is '{$reference}', which Docker reads as an official "
                .'Docker Hub image. It needs the registry namespace, or provisioning fails '
                .'on the server with "pull access denied".'
            );
        }
    }

    public function test_the_origin_compose_references_those_images(): void
    {
        $origin = Server::factory()->origin()->create(['shared_secret' => 'origin-secret']);
        $compose = $this->provisioning->generateConfig($origin, 'docker-compose');

        foreach (config('stream.images') as $reference) {
            $this->assertStringContainsString($reference, $compose);
        }
    }

    /**
     * The install script has to actually write the heartbeat script.
     *
     * The cron entry pointing at `/opt/streaming/heartbeat.sh` existed for a long time
     * while nothing ever created the file, so no server ever checked in. The column
     * still moved, because UpdateServerViewerCountsJob was stamping `last_heartbeat` on
     * every active edge itself - meaning a box could be dead for hours and still report
     * a fresh heartbeat. Both halves are asserted here.
     */
    public function test_the_install_script_writes_the_heartbeat_script(): void
    {
        $script = $this->provisioning->generateInstallScript($this->edge);

        $this->assertStringContainsString('cat > /opt/streaming/heartbeat.sh', $script);
        // Blade fills the identity in; the URL itself is assembled by the shell at
        // run time, so assert the parts rather than a joined string.
        $this->assertStringContainsString('SERVER_ID="'.$this->edge->id.'"', $script);
        $this->assertStringContainsString('SHARED_SECRET="'.$this->edge->shared_secret.'"', $script);
        $this->assertStringContainsString('/api/server/${SERVER_ID}/heartbeat', $script);
        $this->assertStringContainsString('X-Shared-Secret: ${SHARED_SECRET}', $script);

        // Cron alone was the bug; the file has to exist and be runnable.
        $this->assertStringContainsString('chmod 700 /opt/streaming/heartbeat.sh', $script);
        $this->assertStringContainsString('* * * * * /opt/streaming/heartbeat.sh', $script);
    }

    public function test_the_app_does_not_stamp_its_own_heartbeat(): void
    {
        $job = file_get_contents(app_path('Jobs/UpdateServerViewerCountsJob.php'));

        // Strip comments, which explain exactly why this must not happen.
        $code = preg_replace('#//.*$#m', '', $job);

        $this->assertStringNotContainsString(
            "'last_heartbeat'",
            $code,
            'UpdateServerViewerCountsJob must not write last_heartbeat. That column means '
            .'the server reported in; the app writing it makes a dead box look alive.'
        );
    }
}
