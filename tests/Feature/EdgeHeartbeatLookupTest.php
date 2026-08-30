<?php

namespace Tests\Feature;

use App\Models\Server;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Which server an edge's aggregate heartbeat is attributed to.
 *
 * The container-name fallback exists for local development, where the edge reports a
 * name nothing was provisioned under. It used to key off a `hetzner_id = 'manual'`
 * sentinel, which matched one seeded row and nothing else.
 */
class EdgeHeartbeatLookupTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_heartbeat_is_matched_by_its_provider_id(): void
    {
        $server = Server::factory()->cloud()->create(['hostname' => 'edge-1.example.test']);

        $this->postJson(route('api.hls.heartbeat'), [
            'server_id' => $server->external_id,
            'viewer_count' => 7,
            'timestamp' => now()->toIso8601String(),
        ])->assertSuccessful();

        $this->assertNotNull($server->fresh()->last_heartbeat);
    }

    /**
     * Broadening the fallback from a sentinel to `provider = manual` made it match every
     * hand-registered server, so a stray heartbeat from anywhere on the internet attached
     * itself to whichever manual edge happened to sort first.
     */
    public function test_a_stray_docker_heartbeat_does_not_attach_to_a_manual_edge_in_production(): void
    {
        // The container's own binding, not the config key: App::isLocal() reads the one
        // resolved at bootstrap.
        $this->app['env'] = 'production';

        $edge = Server::factory()->create(['hostname' => 'edge-real.example.org']);

        $this->postJson(route('api.hls.heartbeat'), [
            'server_id' => 'docker-edge-1',
            'viewer_count' => 9999,
            'timestamp' => now()->toIso8601String(),
        ])->assertNotFound();

        $this->assertNull($edge->fresh()->last_heartbeat);
    }

    public function test_the_container_fallback_still_works_locally(): void
    {
        $this->app['env'] = 'local';

        $edge = Server::factory()->create(['hostname' => 'edge-local.test']);

        $this->postJson(route('api.hls.heartbeat'), [
            'server_id' => 'docker-edge-1',
            'viewer_count' => 3,
            'timestamp' => now()->toIso8601String(),
        ])->assertSuccessful();

        $this->assertNotNull($edge->fresh()->last_heartbeat);
    }
}
