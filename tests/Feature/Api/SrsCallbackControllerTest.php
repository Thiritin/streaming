<?php

namespace Tests\Feature\Api;

use App\Enum\ServerStatusEnum;
use App\Enum\ServerTypeEnum;
use App\Enum\SourceStatusEnum;
use App\Models\Server;
use App\Models\Show;
use App\Models\Source;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SrsCallbackControllerTest extends TestCase
{
    use RefreshDatabase;

    private Source $source;
    private Server $originServer;
    private Server $edgeServer;
    private Show $show;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a source with a known stream key
        $this->source = Source::create([
            'name' => 'Test Source',
            'slug' => 'test-source',
            'description' => 'Test source description',
            'stream_key' => 'test_stream_key_123',
            'status' => SourceStatusEnum::OFFLINE,
        ]);

        // Create an origin server
        $this->originServer = Server::create([
            'hostname' => 'origin.example.com',
            'ip' => '192.168.1.100',
            'status' => ServerStatusEnum::ACTIVE,
            'type' => ServerTypeEnum::ORIGIN,
            'shared_secret' => 'origin_shared_secret_123',
            'max_clients' => 1000,
            'immutable' => false,
        ]);

        // Create an edge server
        $this->edgeServer = Server::create([
            'hostname' => 'edge.example.com',
            'ip' => '192.168.1.101',
            'status' => ServerStatusEnum::ACTIVE,
            'type' => ServerTypeEnum::EDGE,
            'shared_secret' => 'edge_shared_secret_456',
            'max_clients' => 500,
            'immutable' => false,
        ]);

        // Create a show associated with the source (admin-managed)
        $this->show = Show::create([
            'title' => 'Test Show',
            'slug' => 'test-show',
            'source_id' => $this->source->id,
            'description' => 'Test show description',
            'status' => 'scheduled',
            'scheduled_start' => now()->addHour(),
            'scheduled_end' => now()->addHours(2),
        ]);
    }

    /**
     * Test successful authentication with valid source stream key updates source status
     */
    public function test_auth_succeeds_with_valid_source_stream_key()
    {
        $response = $this->postJson('/api/srs/auth', [
            'app' => 'live',
            'stream' => 'test-source',
            'tcUrl' => 'rtmp://localhost/live',
            'param' => '?secret=' . $this->source->stream_key,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'code' => 0,
                'client' => [
                    'id' => (string) $this->source->id,
                ],
            ])
            ->assertJsonStructure([
                'code',
                'client' => ['id', 'signature'],
            ]);

        // Verify source status was updated to online
        $this->source->refresh();
        $this->assertEquals(SourceStatusEnum::ONLINE, $this->source->status);

        // Verify show status is NOT modified by SRS webhook (managed by admin)
        $this->show->refresh();
        $this->assertEquals('scheduled', $this->show->status); // Remains as set in setUp
    }

    /**
     * Test authentication fails with invalid stream key
     */
    public function test_auth_fails_with_invalid_stream_key()
    {
        $response = $this->postJson('/api/srs/auth', [
            'app' => 'live',
            'stream' => 'test-source',
            'tcUrl' => 'rtmp://localhost/live',
            'param' => '?secret=invalid_key',
        ]);

        $response->assertStatus(403)
            ->assertJson(['code' => 403]);

        // Verify source status remains offline
        $this->source->refresh();
        $this->assertEquals(SourceStatusEnum::OFFLINE, $this->source->status);
    }

    /**
     * Test authentication fails when stream name doesn't match any source
     */
    public function test_auth_fails_with_unknown_stream_name()
    {
        $response = $this->postJson('/api/srs/auth', [
            'app' => 'live',
            'stream' => 'non-existent-stream',
            'tcUrl' => 'rtmp://localhost/live',
            'param' => '?secret=' . $this->source->stream_key,
        ]);

        $response->assertStatus(403)
            ->assertJson(['code' => 403]);
    }

    /**
     * Test authentication fails when no stream key provided
     */
    public function test_auth_fails_without_stream_key()
    {
        $response = $this->postJson('/api/srs/auth', [
            'app' => 'live',
            'stream' => 'test-source',
            'tcUrl' => 'rtmp://localhost/live',
            'param' => '',
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
            'app' => 'live',
            'stream' => 'test-source',
            'tcUrl' => 'rtmp://origin.server/live',
            'param' => '?shared_secret=' . $this->edgeServer->shared_secret,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'code' => 0,
                'server' => [
                    'id' => (string) $this->edgeServer->id,
                ],
            ])
            ->assertJsonStructure([
                'code',
                'server' => ['id', 'signature'],
            ]);

        // Verify source status was updated to online (even for server-to-server)
        $this->source->refresh();
        $this->assertEquals(SourceStatusEnum::ONLINE, $this->source->status);
    }

    /**
     * Test server-to-server authentication fails with invalid shared secret
     */
    public function test_server_auth_fails_with_invalid_shared_secret()
    {
        $response = $this->postJson('/api/srs/auth', [
            'app' => 'live',
            'stream' => 'test-source',
            'tcUrl' => 'rtmp://origin.server/live',
            'param' => '?shared_secret=invalid_secret',
        ]);

        $response->assertStatus(403)
            ->assertJson(['code' => 403]);
    }

    /**
     * Test server-to-server authentication fails with inactive server
     */
    public function test_server_auth_fails_with_inactive_server()
    {
        // Set server to inactive
        $this->edgeServer->status = ServerStatusEnum::DELETED;
        $this->edgeServer->save();

        $response = $this->postJson('/api/srs/auth', [
            'app' => 'live',
            'stream' => 'test-source',
            'tcUrl' => 'rtmp://origin.server/live',
            'param' => '?shared_secret=' . $this->edgeServer->shared_secret,
        ]);

        $response->assertStatus(403)
            ->assertJson(['code' => 403]);
    }

    /**
     * Test that shared secret takes precedence over stream key
     */
    public function test_shared_secret_takes_precedence_over_stream_key()
    {
        $response = $this->postJson('/api/srs/auth', [
            'app' => 'live',
            'stream' => 'test-source',
            'tcUrl' => 'rtmp://origin.server/live',
            'param' => '?shared_secret=' . $this->edgeServer->shared_secret . '&secret=' . $this->source->stream_key,
        ]);

        // Should authenticate as server, not source
        $response->assertStatus(200)
            ->assertJson([
                'code' => 0,
                'server' => [
                    'id' => (string) $this->edgeServer->id,
                ],
            ])
            ->assertJsonMissing(['client']);
    }

    /**
     * Test unpublish webhook sets source to ERROR when show is still live
     */
    public function test_unpublish_sets_source_to_error_when_show_is_live()
    {
        // First set source to online
        $this->source->status = SourceStatusEnum::ONLINE;
        $this->source->save();

        // Set show to live (admin controlled)
        $this->show->status = 'live';
        $this->show->actual_start = now();
        $this->show->save();

        $response = $this->postJson('/api/srs/unpublish', [
            'app' => 'live',
            'stream' => 'test-source',
            'tcUrl' => 'rtmp://localhost/live',
            'param' => '?secret=' . $this->source->stream_key,
        ]);

        $response->assertStatus(200)
            ->assertJson(['code' => 0]);

        // Verify source status was updated to ERROR (unexpected disconnect)
        $this->source->refresh();
        $this->assertEquals(SourceStatusEnum::ERROR, $this->source->status);

        // Verify show status is NOT modified by SRS webhook (managed by admin)
        $this->show->refresh();
        $this->assertEquals('live', $this->show->status);
    }
    
    /**
     * Test unpublish webhook sets source to OFFLINE when no live show
     */
    public function test_unpublish_sets_source_to_offline_when_no_live_show()
    {
        // First set source to online
        $this->source->status = SourceStatusEnum::ONLINE;
        $this->source->save();

        // Show is not live (scheduled or ended)
        $this->show->status = 'scheduled';
        $this->show->save();

        $response = $this->postJson('/api/srs/unpublish', [
            'app' => 'live',
            'stream' => 'test-source',
            'tcUrl' => 'rtmp://localhost/live',
            'param' => '?secret=' . $this->source->stream_key,
        ]);

        $response->assertStatus(200)
            ->assertJson(['code' => 0]);

        // Verify source status was updated to OFFLINE (expected shutdown)
        $this->source->refresh();
        $this->assertEquals(SourceStatusEnum::OFFLINE, $this->source->status);
    }

    /**
     * Test unpublish webhook handles unknown stream gracefully
     */
    public function test_unpublish_handles_unknown_stream_gracefully()
    {
        $response = $this->postJson('/api/srs/unpublish', [
            'app' => 'live',
            'stream' => 'non-existent-stream',
            'tcUrl' => 'rtmp://localhost/live',
            'param' => '',
        ]);

        // Should still return success but log a warning
        $response->assertStatus(200)
            ->assertJson(['code' => 0]);
    }

    /**
     * Test play webhook returns success
     */
    public function test_play_webhook_returns_success()
    {
        $response = $this->postJson('/api/srs/play', [
            'app' => 'live',
            'stream' => 'test-source',
            'tcUrl' => 'rtmp://localhost/live',
            'pageUrl' => 'http://example.com',
            'param' => '',
        ]);

        $response->assertStatus(200)
            ->assertJson(['code' => 0]);
    }

    /**
     * Test stop webhook returns success
     */
    public function test_stop_webhook_returns_success()
    {
        $response = $this->postJson('/api/srs/stop', [
            'app' => 'live',
            'stream' => 'test-source',
            'tcUrl' => 'rtmp://localhost/live',
            'param' => '',
        ]);

        $response->assertStatus(200)
            ->assertJson(['code' => 0]);
    }

    /**
     * Test authentication with malformed param string
     */
    public function test_auth_handles_malformed_param_string()
    {
        $response = $this->postJson('/api/srs/auth', [
            'app' => 'live',
            'stream' => 'test-source',
            'tcUrl' => 'rtmp://localhost/live',
            'param' => 'malformed&&&==param',
        ]);

        $response->assertStatus(403)
            ->assertJson(['code' => 403]);
    }

    /**
     * Test source status changes do not affect show status
     */
    public function test_source_status_changes_do_not_affect_show_status()
    {
        // Create additional show for the same source
        $show2 = Show::create([
            'title' => 'Second Test Show',
            'slug' => 'second-test-show',
            'source_id' => $this->source->id,
            'description' => 'Second test show',
            'status' => 'live', // One show is live
            'scheduled_start' => now()->subHour(),
            'scheduled_end' => now()->addHour(),
        ]);

        $response = $this->postJson('/api/srs/auth', [
            'app' => 'live',
            'stream' => 'test-source',
            'tcUrl' => 'rtmp://localhost/live',
            'param' => '?secret=' . $this->source->stream_key,
        ]);

        $response->assertStatus(200);

        // Source should be online
        $this->source->refresh();
        $this->assertEquals(SourceStatusEnum::ONLINE, $this->source->status);

        // Shows should maintain their original status (not modified by webhook)
        $this->show->refresh();
        $show2->refresh();
        
        $this->assertEquals('scheduled', $this->show->status); // Remains scheduled
        $this->assertEquals('live', $show2->status); // Remains live
    }

    /**
     * Test source going to error when shows are still live
     */
    public function test_source_going_to_error_when_shows_are_live()
    {
        // Set source to online
        $this->source->status = SourceStatusEnum::ONLINE;
        $this->source->save();

        // Set shows to live
        $this->show->status = 'live';
        $this->show->actual_start = now()->subHour();
        $this->show->save();

        $show2 = Show::create([
            'title' => 'Second Test Show',
            'slug' => 'second-test-show',
            'source_id' => $this->source->id,
            'description' => 'Second test show',
            'status' => 'live',
            'actual_start' => now()->subMinutes(30),
            'scheduled_start' => now()->subHour(),
            'scheduled_end' => now()->addHour(),
        ]);

        $response = $this->postJson('/api/srs/unpublish', [
            'app' => 'live',
            'stream' => 'test-source',
            'tcUrl' => 'rtmp://localhost/live',
            'param' => '',
        ]);

        $response->assertStatus(200);

        // Source should be in ERROR state (because shows are still live)
        $this->source->refresh();
        $this->assertEquals(SourceStatusEnum::ERROR, $this->source->status);

        // Shows should maintain their status (not modified by webhook)
        $this->show->refresh();
        $show2->refresh();
        
        $this->assertEquals('live', $this->show->status); // Remains live
        $this->assertEquals('live', $show2->status); // Remains live
    }

    /**
     * Test authentication generates correct signature format
     */
    public function test_auth_generates_correct_signature_format()
    {
        $response = $this->postJson('/api/srs/auth', [
            'app' => 'live',
            'stream' => 'test-source',
            'tcUrl' => 'rtmp://localhost/live',
            'param' => '?secret=' . $this->source->stream_key,
        ]);

        $response->assertStatus(200);
        $data = $response->json();

        // Verify signature is an MD5 hash (32 characters)
        $this->assertArrayHasKey('client', $data);
        $this->assertArrayHasKey('signature', $data['client']);
        $this->assertEquals(32, strlen($data['client']['signature']));
        
        // Verify signature matches expected format
        $expectedSignature = md5($this->source->id . ':' . $this->source->stream_key);
        $this->assertEquals($expectedSignature, $data['client']['signature']);
    }

    /**
     * Test server authentication generates correct signature format
     */
    public function test_server_auth_generates_correct_signature_format()
    {
        $response = $this->postJson('/api/srs/auth', [
            'app' => 'live',
            'stream' => 'test-source',
            'tcUrl' => 'rtmp://origin.server/live',
            'param' => '?shared_secret=' . $this->edgeServer->shared_secret,
        ]);

        $response->assertStatus(200);
        $data = $response->json();

        // Verify signature is an MD5 hash (32 characters)
        $this->assertArrayHasKey('server', $data);
        $this->assertArrayHasKey('signature', $data['server']);
        $this->assertEquals(32, strlen($data['server']['signature']));
        
        // Verify signature matches expected format
        $expectedSignature = md5($this->edgeServer->id . ':' . $this->edgeServer->shared_secret);
        $this->assertEquals($expectedSignature, $data['server']['signature']);
    }

    /**
     * Test that source with encrypted stream_key field works correctly
     */
    public function test_source_with_encrypted_stream_key_authenticates()
    {
        // Create a new source to ensure encryption is working
        $encryptedSource = Source::create([
            'name' => 'Encrypted Source',
            'slug' => 'encrypted-source',
            'description' => 'Source with encrypted key',
            'stream_key' => 'super_secret_key_456',
            'status' => SourceStatusEnum::OFFLINE,
        ]);

        // Verify the stream_key is stored encrypted in database
        $rawData = \DB::table('sources')->where('id', $encryptedSource->id)->first();
        $this->assertNotEquals('super_secret_key_456', $rawData->stream_key);

        // But authentication should still work with the plain key
        $response = $this->postJson('/api/srs/auth', [
            'app' => 'live',
            'stream' => 'encrypted-source',
            'tcUrl' => 'rtmp://localhost/live',
            'param' => '?secret=super_secret_key_456',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'code' => 0,
                'client' => [
                    'id' => (string) $encryptedSource->id,
                ],
            ]);
    }

    /**
     * Test concurrent auth requests for same source handle correctly
     */
    public function test_concurrent_auth_requests_for_same_source()
    {
        // Simulate multiple simultaneous auth requests
        $responses = [];
        
        for ($i = 0; $i < 3; $i++) {
            $responses[] = $this->postJson('/api/srs/auth', [
                'app' => 'live',
                'stream' => 'test-source',
                'tcUrl' => 'rtmp://localhost/live',
                'param' => '?secret=' . $this->source->stream_key,
            ]);
        }

        // All should succeed
        foreach ($responses as $response) {
            $response->assertStatus(200)
                ->assertJson(['code' => 0]);
        }

        // Source should be online
        $this->source->refresh();
        $this->assertEquals(SourceStatusEnum::ONLINE, $this->source->status);
    }

    /**
     * Test edge case: empty stream name
     */
    public function test_auth_fails_with_empty_stream_name()
    {
        $response = $this->postJson('/api/srs/auth', [
            'app' => 'live',
            'stream' => '',
            'tcUrl' => 'rtmp://localhost/live',
            'param' => '?secret=' . $this->source->stream_key,
        ]);

        $response->assertStatus(403)
            ->assertJson(['code' => 403]);
    }

    /**
     * Test edge case: null parameters
     */
    public function test_auth_handles_null_parameters()
    {
        $response = $this->postJson('/api/srs/auth', [
            'app' => null,
            'stream' => null,
            'tcUrl' => null,
            'param' => null,
        ]);

        $response->assertStatus(403)
            ->assertJson(['code' => 403]);
    }

    /**
     * Test server-to-server auth with non-existent source still succeeds
     */
    public function test_server_auth_with_nonexistent_source_still_succeeds()
    {
        $response = $this->postJson('/api/srs/auth', [
            'app' => 'live',
            'stream' => 'non-existent-stream',
            'tcUrl' => 'rtmp://origin.server/live',
            'param' => '?shared_secret=' . $this->edgeServer->shared_secret,
        ]);

        // Server auth should succeed even if source doesn't exist
        $response->assertStatus(200)
            ->assertJson([
                'code' => 0,
                'server' => [
                    'id' => (string) $this->edgeServer->id,
                ],
            ]);
    }

    /**
     * Test source status transitions: offline -> online -> error -> online -> offline
     */
    public function test_source_status_transitions_with_error_recovery()
    {
        // Initially offline
        $this->assertEquals(SourceStatusEnum::OFFLINE, $this->source->status);

        // Authenticate to go online
        $response = $this->postJson('/api/srs/auth', [
            'app' => 'live',
            'stream' => 'test-source',
            'tcUrl' => 'rtmp://localhost/live',
            'param' => '?secret=' . $this->source->stream_key,
        ]);
        $response->assertStatus(200);

        $this->source->refresh();
        $this->assertEquals(SourceStatusEnum::ONLINE, $this->source->status);

        // Set show to live to simulate active stream
        $this->show->status = 'live';
        $this->show->save();

        // Unpublish while show is live -> goes to ERROR
        $response = $this->postJson('/api/srs/unpublish', [
            'app' => 'live',
            'stream' => 'test-source',
            'tcUrl' => 'rtmp://localhost/live',
            'param' => '',
        ]);
        $response->assertStatus(200);

        $this->source->refresh();
        $this->assertEquals(SourceStatusEnum::ERROR, $this->source->status);

        // Reconnect (auth again) to recover from error
        $response = $this->postJson('/api/srs/auth', [
            'app' => 'live',
            'stream' => 'test-source',
            'tcUrl' => 'rtmp://localhost/live',
            'param' => '?secret=' . $this->source->stream_key,
        ]);
        $response->assertStatus(200);

        $this->source->refresh();
        $this->assertEquals(SourceStatusEnum::ONLINE, $this->source->status);

        // End the show
        $this->show->status = 'ended';
        $this->show->save();

        // Unpublish with no live show -> goes to OFFLINE
        $response = $this->postJson('/api/srs/unpublish', [
            'app' => 'live',
            'stream' => 'test-source',
            'tcUrl' => 'rtmp://localhost/live',
            'param' => '',
        ]);
        $response->assertStatus(200);

        $this->source->refresh();
        $this->assertEquals(SourceStatusEnum::OFFLINE, $this->source->status);
    }
}