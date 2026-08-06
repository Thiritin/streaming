<?php

namespace Tests\Feature;

use App\Models\Server;
use App\Models\Source;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SrsWebhookAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private Server $server;

    private Source $source;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a server with shared secret
        $this->server = Server::create([
            'hostname' => 'localhost:8080',
            'ip' => 1,
            'status' => \App\Enum\ServerStatusEnum::ACTIVE,
            'type' => \App\Enum\ServerTypeEnum::EDGE,
            'shared_secret' => 'test_shared_secret_123',
            'max_clients' => 100,
            'immutable' => false,
        ]);

        // Create a source with slug 'livestream' for authentication tests
        $this->source = Source::create([
            'name' => 'Test Stream',
            'slug' => 'livestream',
            'stream_key' => 'valid_source_key_123',
            'priority' => 10,
            'status' => \App\Enum\SourceStatusEnum::OFFLINE,
        ]);
    }

    /**
     * Publisher authentication is per source, not per user.
     *
     * User-based authentication tests have been removed. `/api/srs/auth` compares `?secret=`
     * against the source's own `stream_key`, or `?shared_secret=` for edge-to-origin forwards.
     * A user's streamkey is only used for HLS playback (onHls), not publishing.
     * See docs/dev-stack.md.
     */

    /**
     * Test source authentication succeeds with valid stream key
     */
    public function test_source_auth_succeeds_with_valid_key()
    {
        $response = $this->postJson('/api/srs/auth', [
            'app' => 'ingress',
            'stream' => 'livestream',
            'tcUrl' => 'rtmp://localhost/ingress',
            'pageUrl' => '',
            'param' => '?secret='.$this->source->stream_key,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'code' => 0,
                'client' => [
                    'id' => (string) $this->source->id,
                ],
            ]);
    }

    /**
     * Test authentication fails with invalid source key
     */
    public function test_auth_fails_with_invalid_source_key()
    {
        $response = $this->postJson('/api/srs/auth', [
            'app' => 'ingress',
            'stream' => 'livestream',
            'tcUrl' => 'rtmp://localhost/ingress',
            'pageUrl' => '',
            'param' => '?secret=invalid_streamkey_456',
        ]);

        $response->assertStatus(403)
            ->assertJson(['code' => 403]);
    }

    /**
     * Test authentication fails when no stream key provided
     */
    public function test_auth_fails_without_streamkey()
    {
        $response = $this->postJson('/api/srs/auth', [
            'app' => 'ingress',
            'stream' => 'livestream',
            'tcUrl' => 'rtmp://localhost/ingress',
            'pageUrl' => '',
            'param' => '',
        ]);

        $response->assertStatus(403)
            ->assertJson(['code' => 403]);
    }

    /**
     * Test authentication fails when source does not exist
     */
    public function test_auth_fails_for_nonexistent_source()
    {
        $response = $this->postJson('/api/srs/auth', [
            'app' => 'ingress',
            'stream' => 'nonexistent',
            'tcUrl' => 'rtmp://localhost/ingress',
            'pageUrl' => '',
            'param' => '?secret=any_key',
        ]);

        $response->assertStatus(403)
            ->assertJson(['code' => 403]);
    }

    /**
     * Test server-to-server authentication with valid shared secret
     */
    public function test_server_auth_succeeds_with_valid_shared_secret()
    {
        $response = $this->postJson('/api/srs/auth', [
            'app' => 'ingress',
            'stream' => 'livestream',
            'tcUrl' => 'rtmp://origin.server/live',
            'pageUrl' => '',
            'param' => '?shared_secret='.$this->server->shared_secret,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'code' => 0,
                'server' => [
                    'id' => (string) $this->server->id,
                ],
            ])
            ->assertJsonStructure([
                'code',
                'server' => ['id', 'signature'],
            ]);
    }

    /**
     * Test server-to-server authentication fails with invalid shared secret
     */
    public function test_server_auth_fails_with_invalid_shared_secret()
    {
        $response = $this->postJson('/api/srs/auth', [
            'app' => 'ingress',
            'stream' => 'livestream',
            'tcUrl' => 'rtmp://origin.server/live',
            'pageUrl' => '',
            'param' => '?shared_secret=invalid_secret_789',
        ]);

        $response->assertStatus(403)
            ->assertJson(['code' => 403]);
    }

    /**
     * Test on_unpublish webhook returns success
     */
    public function test_unpublish_webhook_returns_success()
    {
        $response = $this->postJson('/api/srs/unpublish', [
            'app' => 'ingress',
            'stream' => 'livestream',
            'tcUrl' => 'rtmp://localhost/ingress',
            'pageUrl' => '',
            'param' => '?secret='.$this->source->stream_key,
        ]);

        $response->assertStatus(200)
            ->assertJson(['code' => 0]);
    }

    /**
     * Test that shared secret takes precedence over streamkey
     */
    public function test_shared_secret_takes_precedence_over_streamkey()
    {
        // Send both shared_secret and streamkey
        $response = $this->postJson('/api/srs/auth', [
            'app' => 'ingress',
            'stream' => 'livestream',
            'tcUrl' => 'rtmp://origin.server/live',
            'pageUrl' => '',
            'param' => '?shared_secret='.$this->server->shared_secret.'&secret=some_streamkey',
        ]);

        // Should authenticate as server, not user
        $response->assertStatus(200)
            ->assertJson([
                'code' => 0,
                'server' => [
                    'id' => (string) $this->server->id,
                ],
            ])
            ->assertJsonMissing(['client']);
    }

    /**
     * Test authentication with malformed param string
     */
    public function test_auth_handles_malformed_param_string()
    {
        $response = $this->postJson('/api/srs/auth', [
            'app' => 'ingress',
            'stream' => 'livestream',
            'tcUrl' => 'rtmp://localhost/ingress',
            'pageUrl' => '',
            'param' => 'malformed&&&==param',
        ]);

        $response->assertStatus(403)
            ->assertJson(['code' => 403]);
    }
}
