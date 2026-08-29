<?php

namespace Tests\Feature\Api;

use App\Enum\ServerStatusEnum;
use App\Enum\ServerTypeEnum;
use App\Models\Server;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * How a streaming server proves it is the server it claims to be.
 *
 * The old scheme took a plaintext secret from the query string, compared it with `!==`,
 * and found the row *by* the secret - so the credential was in every access log on the
 * path and any box holding a valid one could address any other box's endpoints, the
 * origin's rendered config included. That config carries the DVR credentials.
 *
 * Every case below is a way through that must stay shut.
 */
class ServerAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'edge-shared-secret-value';

    private Server $edge;

    protected function setUp(): void
    {
        parent::setUp();

        $this->edge = Server::factory()->credential(self::SECRET)->create([
            'type' => ServerTypeEnum::EDGE,
            'status' => ServerStatusEnum::ACTIVE,
        ]);
    }

    public function test_the_right_secret_in_the_header_is_accepted(): void
    {
        $this->postJson(
            "/api/server/{$this->edge->id}/heartbeat",
            [],
            ['X-Shared-Secret' => self::SECRET],
        )->assertOk();
    }

    public function test_a_wrong_secret_is_rejected(): void
    {
        $this->postJson(
            "/api/server/{$this->edge->id}/heartbeat",
            [],
            ['X-Shared-Secret' => 'not-the-secret'],
        )->assertStatus(401);
    }

    public function test_a_missing_secret_is_rejected(): void
    {
        $this->postJson("/api/server/{$this->edge->id}/heartbeat")->assertStatus(401);
    }

    public function test_an_empty_secret_is_rejected(): void
    {
        $this->postJson(
            "/api/server/{$this->edge->id}/heartbeat",
            [],
            ['X-Shared-Secret' => ''],
        )->assertStatus(401);
    }

    /**
     * The hole this whole change exists for.
     *
     * Two boxes, each with a valid credential. The second one's credential must not open
     * the first one's endpoints - it used to, because the row was looked up by the secret
     * rather than resolved from the path and then checked.
     */
    public function test_another_servers_secret_does_not_open_this_servers_endpoints(): void
    {
        $origin = Server::factory()->origin()->credential('origin-shared-secret-value')->create([
            'status' => ServerStatusEnum::ACTIVE,
        ]);

        $this->postJson(
            "/api/server/{$origin->id}/heartbeat",
            [],
            ['X-Shared-Secret' => self::SECRET],
        )->assertStatus(401);

        // The config endpoint is the one that mattered most: the origin's carries the
        // DVR S3 credentials and the SRS configuration.
        $this->getJson(
            "/api/server/{$origin->id}/config/srs-origin",
            ['X-Shared-Secret' => self::SECRET],
        )->assertStatus(401);

        $this->getJson(
            "/api/server/{$origin->id}/scripts/install",
            ['X-Shared-Secret' => self::SECRET],
        )->assertStatus(401);
    }

    public function test_a_secret_in_the_query_string_is_not_accepted(): void
    {
        $secret = self::SECRET;

        $this->postJson("/api/server/{$this->edge->id}/heartbeat?shared_secret={$secret}")
            ->assertStatus(401);

        $this->getJson("/api/server/{$this->edge->id}/config/nginx-edge?shared_secret={$secret}")
            ->assertStatus(401);

        $this->getJson("/api/server/{$this->edge->id}/scripts/install?shared_secret={$secret}")
            ->assertStatus(401);

        $this->postJson("/api/server/{$this->edge->id}/register?shared_secret={$secret}", [
            'hostname' => 'edge-1.test',
            'ip' => '203.0.113.10',
            'status' => ServerStatusEnum::ACTIVE->value,
        ])->assertStatus(401);
    }

    /**
     * A row with nothing stored must not be openable by presenting nothing.
     */
    public function test_a_server_with_no_credential_stored_rejects_everything(): void
    {
        $this->edge->forceFill(['shared_secret_hash' => null])->save();

        $this->postJson("/api/server/{$this->edge->id}/heartbeat")->assertStatus(401);

        $this->postJson(
            "/api/server/{$this->edge->id}/heartbeat",
            [],
            ['X-Shared-Secret' => self::SECRET],
        )->assertStatus(401);

        $this->edge->forceFill(['shared_secret_hash' => ''])->save();

        $this->postJson(
            "/api/server/{$this->edge->id}/heartbeat",
            [],
            ['X-Shared-Secret' => ''],
        )->assertStatus(401);
    }

    /**
     * 401 and not 404: these endpoints serve credentials, so an unauthenticated caller
     * must not be able to read which server ids exist off the status code.
     */
    public function test_a_server_row_that_does_not_exist_is_rejected_the_same_way(): void
    {
        $this->postJson(
            '/api/server/999999/heartbeat',
            [],
            ['X-Shared-Secret' => self::SECRET],
        )->assertStatus(401);

        $this->getJson(
            '/api/server/999999/config/nginx-edge',
            ['X-Shared-Secret' => self::SECRET],
        )->assertStatus(401);

        // Nor off a malformed one.
        $this->postJson(
            '/api/server/not-a-number/heartbeat',
            [],
            ['X-Shared-Secret' => self::SECRET],
        )->assertStatus(401);
    }

    /**
     * A torn-down row's leaked credential is inert.
     */
    public function test_a_deleted_server_is_rejected_even_with_the_right_secret(): void
    {
        $this->edge->forceFill(['status' => ServerStatusEnum::DELETED])->save();

        $this->postJson(
            "/api/server/{$this->edge->id}/heartbeat",
            [],
            ['X-Shared-Secret' => self::SECRET],
        )->assertStatus(401);
    }

    public function test_rotation_invalidates_the_previous_secret(): void
    {
        $rotated = $this->edge->issueCredentials();

        $this->postJson(
            "/api/server/{$this->edge->id}/heartbeat",
            [],
            ['X-Shared-Secret' => self::SECRET],
        )->assertStatus(401);

        $this->postJson(
            "/api/server/{$this->edge->id}/heartbeat",
            [],
            ['X-Shared-Secret' => $rotated->sharedSecret],
        )->assertOk();
    }

    /**
     * Deploy authority is a second credential so the two can be revoked apart: rotating
     * the deploy token must not blind the dashboard, and a heartbeat credential must not
     * be enough to claim deploy work.
     */
    public function test_the_deploy_token_is_a_separate_credential(): void
    {
        $credentials = $this->edge->issueCredentials();
        $server = $this->edge->fresh();

        $this->assertTrue($server->verifyDeployToken($credentials->deployToken));
        $this->assertFalse($server->verifyDeployToken($credentials->sharedSecret));
        $this->assertFalse($server->verifySharedSecret($credentials->deployToken));
        $this->assertFalse($server->verifyDeployToken(null));
        $this->assertFalse($server->verifyDeployToken(''));
    }

    public function test_only_hashes_are_stored(): void
    {
        $credentials = $this->edge->issueCredentials();

        $row = (array) \DB::table('servers')->where('id', $this->edge->id)->first();

        $this->assertArrayNotHasKey('shared_secret', $row);

        foreach ($row as $value) {
            if (is_string($value)) {
                $this->assertNotSame($credentials->sharedSecret, $value);
                $this->assertNotSame($credentials->deployToken, $value);
            }
        }

        $this->assertSame(
            hash('sha256', $credentials->sharedSecret),
            $this->edge->fresh()->shared_secret_hash,
        );
    }

    /**
     * The credential must not reach anything that serialises a row.
     */
    public function test_the_hashes_are_hidden_from_serialisation(): void
    {
        $array = $this->edge->fresh()->toArray();

        $this->assertArrayNotHasKey('shared_secret_hash', $array);
        $this->assertArrayNotHasKey('deploy_token_hash', $array);
    }

    /**
     * The limit is in front of the check, so guessing at a credential is counted too,
     * and it is keyed by the server in the path rather than by address - a rack of edges
     * leaves through one outbound IP.
     */
    public function test_the_endpoints_are_throttled_per_server(): void
    {
        for ($i = 0; $i < 120; $i++) {
            $this->postJson(
                "/api/server/{$this->edge->id}/heartbeat",
                [],
                ['X-Shared-Secret' => 'wrong'],
            )->assertStatus(401);
        }

        $this->postJson(
            "/api/server/{$this->edge->id}/heartbeat",
            [],
            ['X-Shared-Secret' => self::SECRET],
        )->assertStatus(429);

        $other = Server::factory()->credential('other-secret')->create();

        $this->postJson(
            "/api/server/{$other->id}/heartbeat",
            [],
            ['X-Shared-Secret' => 'other-secret'],
        )->assertOk();
    }

    /**
     * A refused box keeps checking in every minute and looks exactly like a crashed one.
     * The app is the side answering 401, so it is the side that can tell them apart.
     */
    public function test_a_refusal_is_recorded_and_a_success_clears_it(): void
    {
        $this->assertNull($this->edge->credential_rejected_at);

        $this->postJson(
            "/api/server/{$this->edge->id}/heartbeat",
            [],
            ['X-Shared-Secret' => 'wrong'],
        )->assertStatus(401);

        $first = $this->edge->fresh()->credential_rejected_at;
        $this->assertNotNull($first);

        // Later refusals leave the first stamp alone: it is when this started.
        $this->travel(2)->minutes();

        $this->postJson(
            "/api/server/{$this->edge->id}/heartbeat",
            [],
            ['X-Shared-Secret' => 'wrong'],
        )->assertStatus(401);

        $this->assertTrue($first->equalTo($this->edge->fresh()->credential_rejected_at));

        $this->postJson(
            "/api/server/{$this->edge->id}/heartbeat",
            [],
            ['X-Shared-Secret' => self::SECRET],
        )->assertOk();

        $this->assertNull($this->edge->fresh()->credential_rejected_at);
    }

    /**
     * Recording it must not look like an edit to the row: `updated_at` stays put and the
     * saved hook that drops the edge caches must not fire, or a box hammering a wrong
     * credential would clear `hls_active_edges` once a minute for every viewer.
     */
    public function test_recording_a_refusal_does_not_touch_the_rest_of_the_row(): void
    {
        Cache::forever('hls_active_edges', ['sentinel']);
        $before = $this->edge->fresh()->updated_at;

        $this->travel(2)->minutes();

        $this->postJson(
            "/api/server/{$this->edge->id}/heartbeat",
            [],
            ['X-Shared-Secret' => 'wrong'],
        )->assertStatus(401);

        $this->assertTrue($before->equalTo($this->edge->fresh()->updated_at));
        $this->assertSame(['sentinel'], Cache::get('hls_active_edges'));
    }

    public function test_a_refused_server_is_not_recorded_for_a_row_that_does_not_exist(): void
    {
        $this->postJson('/api/server/999999/heartbeat', [], ['X-Shared-Secret' => 'wrong'])
            ->assertStatus(401);

        $this->assertNull($this->edge->fresh()->credential_rejected_at);
    }

    public function test_register_writes_the_row_named_in_the_path(): void
    {
        $this->postJson(
            "/api/server/{$this->edge->id}/register",
            [
                'hostname' => 'edge-7.example.test',
                'ip' => '203.0.113.7',
                'status' => ServerStatusEnum::ACTIVE->value,
            ],
            ['X-Shared-Secret' => self::SECRET],
        )->assertOk();

        $this->assertSame('edge-7.example.test', $this->edge->fresh()->hostname);
    }
}
