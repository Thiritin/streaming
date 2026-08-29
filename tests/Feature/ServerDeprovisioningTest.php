<?php

namespace Tests\Feature;

use App\Enum\ServerStatusEnum;
use App\Enum\ServerTypeEnum;
use App\Jobs\Server\Deprovision\InitializeDeprovisioningJob;
use App\Models\Server;
use App\Models\Source;
use App\Models\SourceUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Taking an edge out of rotation.
 *
 * This job used to reassign accounts one at a time. Because a scheduled job pinned every
 * account in the database to an edge whether or not it had ever watched anything, the
 * loop was the size of the `users` table and blew the 60s queue timeout - and since the
 * status was written last, the chain aborted before the server ever left `active`. The
 * operator pressed Deprovision and nothing happened, repeatedly.
 *
 * What matters now: the work is bounded by who is watching, and the status is written
 * first so a later failure is still recoverable.
 */
class ServerDeprovisioningTest extends TestCase
{
    use RefreshDatabase;

    private function edge(string $hostname, int $viewers = 0): Server
    {
        return Server::create([
            'hostname' => $hostname,
            'ip' => '10.0.0.'.random_int(1, 254),
            'port' => 443,
            'type' => ServerTypeEnum::EDGE,
            'status' => ServerStatusEnum::ACTIVE,
            'viewer_count' => $viewers,
            'max_clients' => 100,
        ]);
    }

    private function viewerSession(Server $server, ?User $user = null): SourceUser
    {
        return SourceUser::create([
            'source_id' => Source::factory()->create()->id,
            'user_id' => $user?->id,
            'guest_key' => $user ? null : Str::random(16),
            'server_id' => $server->id,
            'joined_at' => now(),
            'last_heartbeat_at' => now(),
        ]);
    }

    public function test_the_server_leaves_rotation_before_anything_else_happens(): void
    {
        $server = $this->edge('edge-order.test');

        (new InitializeDeprovisioningJob($server))->handle();

        $this->assertEquals(ServerStatusEnum::DEPROVISIONING, $server->fresh()->status);
    }

    public function test_open_sessions_on_the_edge_are_released(): void
    {
        $server = $this->edge('edge-release.test');

        $guest = $this->viewerSession($server);
        $member = $this->viewerSession($server, User::factory()->create());

        (new InitializeDeprovisioningJob($server))->handle();

        $this->assertNull($guest->fresh()->server_id, 'A guest session should be released.');
        $this->assertNull($member->fresh()->server_id, 'A signed-in session should be released too.');
    }

    /**
     * A session that already ended is history. Rewriting it would corrupt the record of
     * which edge actually served that viewer.
     */
    public function test_closed_sessions_are_left_alone(): void
    {
        $server = $this->edge('edge-history.test');

        $closed = $this->viewerSession($server);
        $closed->forceFill(['left_at' => now()->subMinutes(5)])->save();

        (new InitializeDeprovisioningJob($server))->handle();

        $this->assertEquals($server->id, $closed->fresh()->server_id);
    }

    public function test_sessions_on_other_edges_are_untouched(): void
    {
        $doomed = $this->edge('edge-doomed.test');
        $survivor = $this->edge('edge-survivor.test');

        $stays = $this->viewerSession($survivor);

        (new InitializeDeprovisioningJob($doomed))->handle();

        $this->assertEquals($survivor->id, $stays->fresh()->server_id);
        $this->assertEquals(ServerStatusEnum::ACTIVE, $survivor->fresh()->status);
    }

    /**
     * The edge list is cached, so without this the edge being torn down keeps being
     * handed to viewers for the life of the cache entry.
     */
    public function test_the_active_edge_cache_is_dropped(): void
    {
        $server = $this->edge('edge-cache.test');

        Cache::put('hls_active_edges', collect([$server->id => $server]), 60);

        (new InitializeDeprovisioningJob($server))->handle();

        $this->assertNull(Cache::get('hls_active_edges'));
    }

    /**
     * The regression that made this job unusable. The old implementation issued at least
     * one query per account in the database; a fleet with many accounts and nobody
     * watching must now cost a fixed handful.
     */
    public function test_the_work_does_not_scale_with_the_user_table(): void
    {
        $server = $this->edge('edge-scale.test');

        User::factory()->count(200)->create();
        $this->viewerSession($server);

        $queries = 0;
        \DB::listen(function () use (&$queries) {
            $queries++;
        });

        (new InitializeDeprovisioningJob($server))->handle();

        $this->assertLessThan(
            10,
            $queries,
            'Taking an edge out of rotation must cost a fixed number of queries. It once '
            .'ran one per account in the database, which timed out the job and left the '
            .'server stuck in active.'
        );
    }
}
