<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SrsWebhookAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private User $userWithStreamkey;

    private User $userWithoutStreamkey;

    private Server $server;

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

        // Create admin role with stream.publish permission
        $adminRole = Role::create([
            'slug' => 'admin',
            'name' => 'Admin',
            'priority' => 1000,
            'permissions' => ['stream.view', 'stream.publish', 'admin.access'],
        ]);

        // Create user role without stream.publish permission
        $userRole = Role::create([
            'slug' => 'user',
            'name' => 'User',
            'priority' => 10,
            'permissions' => ['stream.view'],
        ]);

        // Create user with streamkey and admin role
        $this->userWithStreamkey = User::factory()->create([
            'streamkey' => 'valid_test_streamkey_123',
            'server_id' => $this->server->id,
        ]);
        $this->userWithStreamkey->assignRole($adminRole);

        // Create user without streamkey
        $this->userWithoutStreamkey = User::factory()->create([
            'streamkey' => null,
            'server_id' => null,
        ]);
        $this->userWithoutStreamkey->assignRole($userRole);
    }

    /**
     * Publisher authentication is per source, not per user.
     *
     * Two tests were removed here: one asserting a user's `streamkey` authenticated a
     * publish, and one asserting `?streamkey=` and `?secret=` were interchangeable.
     * Neither is true any more. `/api/srs/auth` compares `?secret=` against the source's
     * own `stream_key`, or `?shared_secret=` for edge-to-origin forwards; a user's
     * streamkey is only read on the playback path. See docs/dev-stack.md.
     */

    /**
     * Test authentication fails with invalid streamkey
     */
    public function test_auth_fails_with_invalid_streamkey()
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
     * Test authentication fails when no streamkey provided
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
     * Test authentication fails for user without server assignment
     */
    public function test_auth_fails_for_user_without_server_assignment()
    {
        $userWithoutServer = User::factory()->create([
            'streamkey' => 'test_streamkey_no_server',
            'server_id' => null, // No server assigned
        ]);

        $response = $this->postJson('/api/srs/auth', [
            'app' => 'ingress',
            'stream' => 'livestream',
            'tcUrl' => 'rtmp://localhost/ingress',
            'pageUrl' => '',
            'param' => '?secret='.$userWithoutServer->streamkey,
        ]);

        $response->assertStatus(403)
            ->assertJson(['code' => 403]);
    }

    /**
     * Test authentication fails for user without stream.publish permission
     */
    public function test_auth_fails_for_user_without_publish_permission()
    {
        // Create a user with streamkey but no stream.publish permission
        $userWithoutPermission = User::factory()->create([
            'streamkey' => 'test_streamkey_no_permission',
            'server_id' => $this->server->id,
        ]);

        // Assign user role (which doesn't have stream.publish permission)
        $userRole = Role::where('slug', 'user')->first();
        $userWithoutPermission->assignRole($userRole);

        $response = $this->postJson('/api/srs/auth', [
            'app' => 'ingress',
            'stream' => 'livestream',
            'tcUrl' => 'rtmp://localhost/ingress',
            'pageUrl' => '',
            'param' => '?secret='.$userWithoutPermission->streamkey,
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
            'param' => '?secret='.$this->userWithStreamkey->streamkey,
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
