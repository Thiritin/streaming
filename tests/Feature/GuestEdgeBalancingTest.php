<?php

namespace Tests\Feature;

use App\Enum\ServerStatusEnum;
use App\Enum\ServerTypeEnum;
use App\Jobs\UpdateServerViewerCountsJob;
use App\Models\Server;
use App\Models\Show;
use App\Models\Source;
use App\Models\SourceUser;
use App\Models\User;
use App\Services\StreamInfoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Signed-out viewers on an installation with optional login.
 *
 * They used to be invisible to the balancer in both directions: no `source_users` row,
 * so no edge's `viewer_count` ever rose for them, and a fresh least-loaded pick on every
 * request, so they all read the same answer. The result was not a skew, it was total
 * convergence - every guest on one edge no matter how many edges existed.
 */
class GuestEdgeBalancingTest extends TestCase
{
    use RefreshDatabase;

    protected Source $source;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Config::set('auth.required', false);

        $this->source = Source::factory()->create(['slug' => 'main-stage']);

        Http::fake([
            '*_master.m3u8' => Http::response(
                "#EXTM3U\n#EXT-X-STREAM-INF:BANDWIDTH=3500000\nmain-stage_hd.m3u8\n", 200
            ),
        ]);
    }

    public function test_a_guest_gets_a_session_row_and_an_edge(): void
    {
        $edge = $this->edge('a.example.com');

        $this->viewer('guest-one')->get('/hls/main-stage/master.m3u8')->assertOk();

        $session = SourceUser::firstWhere('source_id', $this->source->id);

        $this->assertNotNull($session, 'A signed-out viewer should still get a session row.');
        $this->assertNull($session->user_id);
        $this->assertNotNull($session->guest_key);
        $this->assertSame($edge->id, $session->server_id);
    }

    public function test_a_guest_keeps_the_same_edge_across_requests(): void
    {
        $busy = $this->edge('busy.example.com', viewers: 0);
        $this->edge('idle.example.com', viewers: 0);

        $this->viewer('guest-one')->get('/hls/main-stage/master.m3u8')->assertOk();

        $assigned = SourceUser::firstWhere('source_id', $this->source->id)->server_id;

        // Make the other edge look far more attractive. A sticky assignment ignores it;
        // the old per-request pick would have moved the viewer mid-session.
        Server::whereKey($assigned)->update(['viewer_count' => 90]);
        Server::whereKeyNot($assigned)->update(['viewer_count' => 0]);

        $this->viewer('guest-one')->get('/hls/main-stage/master.m3u8')->assertOk();

        $this->assertSame(
            $assigned,
            SourceUser::firstWhere('source_id', $this->source->id)->server_id,
            'A guest should stay on the edge they were pinned to.',
        );
        $this->assertSame(1, SourceUser::count(), 'The same guest should not open a second session.');
        $this->assertNotNull($busy);
    }

    public function test_guest_sessions_raise_the_edge_viewer_count(): void
    {
        $edge = $this->edge('a.example.com');

        // Three distinct guests. Separate viewer cookies, so separate guest keys.
        foreach (range(1, 3) as $n) {
            $this->viewer('guest-'.$n)->get('/hls/main-stage/master.m3u8')->assertOk();
        }

        $this->assertSame(3, SourceUser::whereNull('user_id')->count());

        UpdateServerViewerCountsJob::dispatchSync();

        $this->assertSame(
            3,
            $edge->fresh()->viewer_count,
            'Guests must count towards an edge, or nothing ever moves off it.',
        );
    }

    public function test_guests_are_included_in_the_live_viewer_count(): void
    {
        $this->edge('a.example.com');

        foreach (range(1, 4) as $n) {
            $this->viewer('guest-'.$n)->get('/hls/main-stage/master.m3u8')->assertOk();
        }

        // Last, because actingAs applies to every request that follows it.
        $signedIn = User::factory()->create();
        $this->actingAs($signedIn)->get('/hls/main-stage/master.m3u8')->assertOk();

        Cache::forget('stream.listeners');

        // COUNT(user_id) skips NULLs, so the guests used to vanish from this entirely
        // and the site reported one viewer while five were watching.
        $this->assertSame(5, StreamInfoService::getUserCount());

        $show = Show::factory()->create([
            'source_id' => $this->source->id,
            'status' => 'live',
        ]);
        $show->updateViewerCount();

        $this->assertSame(5, $show->fresh()->viewer_count);
    }

    public function test_a_guest_is_moved_off_an_edge_that_goes_away(): void
    {
        $first = $this->edge('a.example.com');
        $second = $this->edge('b.example.com');

        $this->viewer('guest-one')->get('/hls/main-stage/master.m3u8')->assertOk();

        $pinned = SourceUser::firstWhere('source_id', $this->source->id)->server_id;
        Server::whereKey($pinned)->update(['status' => ServerStatusEnum::DEPROVISIONING]);

        $this->viewer('guest-one')->get('/hls/main-stage/master.m3u8')->assertOk();

        $now = SourceUser::firstWhere('source_id', $this->source->id)->server_id;

        $this->assertNotSame($pinned, $now, 'A pinned edge that is no longer active must be given up.');
        $this->assertContains($now, [$first->id, $second->id]);
    }

    public function test_a_full_edge_is_skipped_in_favour_of_one_with_headroom(): void
    {
        $this->edge('full.example.com', viewers: 100, max: 100);
        $spare = $this->edge('spare.example.com', viewers: 20, max: 100);

        $this->viewer('guest-one')->get('/hls/main-stage/master.m3u8')->assertOk();

        $this->assertSame(
            $spare->id,
            SourceUser::firstWhere('source_id', $this->source->id)->server_id,
        );
    }

    /**
     * Signed-in viewers, for contrast: the same stickiness, off users.server_id.
     *
     * The second request is the whole point. getOrAssignServer used to read the cached
     * `server` relation, which resolves to null once before assignment and stays null
     * on a reused User instance. It then re-ran assignServerToUser, which deliberately
     * excludes the current edge, found no alternative, and cleared the assignment - so
     * a viewer who had just been given an edge was answered 503 on their next request.
     */
    public function test_a_signed_in_viewer_keeps_their_edge_on_the_next_request(): void
    {
        $edge = $this->edge('a.example.com');
        $user = User::factory()->create(['server_id' => null, 'streamkey' => null]);

        $this->actingAs($user)->get('/hls/main-stage/master.m3u8')->assertOk();
        $this->assertSame($edge->id, $user->fresh()->server_id);

        $this->actingAs($user)->get('/hls/main-stage/master.m3u8')->assertOk();
        $this->assertSame($edge->id, $user->fresh()->server_id);
    }

    /**
     * A browser that already holds a viewer cookie. The id is padded to the 32 chars
     * the controller issues, since anything else is treated as absent and reissued.
     */
    protected function viewer(string $id): static
    {
        return $this->withCookie('viewer_id', str_pad($id, 32, '0'));
    }

    protected function edge(string $hostname, int $viewers = 0, int $max = 100): Server
    {
        return Server::create([
            'hostname' => $hostname,
            'port' => 8080,
            'type' => ServerTypeEnum::EDGE,
            'status' => ServerStatusEnum::ACTIVE,
            'viewer_count' => $viewers,
            'max_clients' => $max,
            'hetzner_id' => 'test-'.$hostname,
            'ip' => '10.0.0.'.random_int(2, 250),
        ]);
    }
}
