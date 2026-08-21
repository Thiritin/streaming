<?php

namespace Tests\Feature;

use App\Enum\ServerStatusEnum;
use App\Enum\ServerTypeEnum;
use App\Enum\SourceStatusEnum;
use App\Models\Server;
use App\Models\Show;
use App\Models\Source;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The main channel, and what happens when something goes wrong on it.
 *
 * A production channel runs a show at all times - back to back, often overlapping -
 * and is watched through a permanent URL: a streamkey in the query string, pasted
 * into VLC or a Smart TV once and never touched again. The show gate must be
 * invisible to it. A handover must not drop the stream, and a fault must say which
 * fault it is rather than looking like a closed channel.
 */
class StreamkeyContinuityTest extends TestCase
{
    use RefreshDatabase;

    private Source $source;

    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        config([
            'stream.token.viewer_secret' => str_repeat('a', 64),
            'stream.system_streamkey' => 'system-key-123',
        ]);

        $this->source = Source::factory()->create([
            'slug' => 'main-stage',
            'name' => 'Main Stage',
            'is_featured' => true,
            'status' => SourceStatusEnum::ONLINE,
        ]);

        $this->viewer = User::factory()->create(['streamkey' => 'viewer-key-123']);

        $this->edge();

        $this->healthyEdge();
    }

    /**
     * Faked once per test rather than in setUp, because Http::fake() adds stubs to
     * whatever is already registered and the first match wins - a later fake in a
     * test would never be reached.
     */
    private function healthyEdge(): void
    {
        Http::fake([
            '*_master.m3u8' => Http::response(
                "#EXTM3U\n#EXT-X-STREAM-INF:BANDWIDTH=3500000\nmain-stage_hd.m3u8\n", 200
            ),
            '*' => Http::response("#EXTM3U\n#EXTINF:2.0,\nmain-stage_hd_000001.ts\n", 200),
        ]);
    }

    private function brokenEdge(int|\Closure $response): void
    {
        // Swapping the factory drops the healthy stubs registered in setUp; faking
        // over them would leave the first match in place.
        Http::swap(new Factory);

        Http::fake(is_int($response)
            ? ['*' => Http::response('boom', $response)]
            : $response);
    }

    private function edge(string $hostname = 'edge.example.com'): Server
    {
        return Server::create([
            'hostname' => $hostname,
            'port' => 8080,
            'type' => ServerTypeEnum::EDGE,
            'status' => ServerStatusEnum::ACTIVE,
            'viewer_count' => 0,
            'max_clients' => 100,
            'hetzner_id' => 'test-'.$hostname,
            'ip' => '10.0.0.1',
        ]);
    }

    private function master(string $query = '?streamkey=viewer-key-123')
    {
        return $this->get('/hls/main-stage/master.m3u8'.$query);
    }

    private function variant(string $query = '?streamkey=viewer-key-123')
    {
        return $this->get('/hls/main-stage_hd.m3u8'.$query);
    }

    // ------------------------------------------------ the always-on channel

    public function test_a_channel_running_a_show_around_the_clock_stays_open(): void
    {
        // The shape of a main channel: one long show covering the whole day.
        Show::factory()->live()->create([
            'source_id' => $this->source->id,
            'scheduled_start' => now()->subHours(6),
            'scheduled_end' => now()->addHours(12),
        ]);

        $this->master()->assertOk();
        $this->variant()->assertOk();

        // Past the window the playable answer is cached for, so this is a fresh
        // decision rather than the first one repeated.
        $this->travel(30)->seconds();

        $this->master()->assertOk();
        $this->variant()->assertOk();
    }

    public function test_a_handover_between_overlapping_shows_never_shuts_the_channel(): void
    {
        // How a stage actually runs: the next slot is put live before the last one is
        // ended, so for a moment both are.
        $ending = Show::factory()->live()->create(['source_id' => $this->source->id]);
        $next = Show::factory()->create([
            'source_id' => $this->source->id,
            'status' => 'scheduled',
        ]);

        $this->master()->assertOk();

        $next->goLive();
        $this->master()->assertOk();

        $ending->endLivestream();
        $this->master()->assertOk();

        // And the viewer's permanent URL is unchanged throughout - nothing about it
        // is per show.
        $this->variant()->assertOk();
    }

    public function test_a_gap_between_shows_closes_the_channel_and_the_next_show_reopens_it(): void
    {
        $first = Show::factory()->live()->create(['source_id' => $this->source->id]);
        $second = Show::factory()->create([
            'source_id' => $this->source->id,
            'status' => 'scheduled',
        ]);

        $this->master()->assertOk();

        $first->endLivestream();
        $this->master()->assertNotFound();

        $second->goLive();
        $this->master()->assertOk();
    }

    public function test_a_show_on_one_channel_does_not_open_another(): void
    {
        Show::factory()->live()->create(['source_id' => $this->source->id]);

        $sideRoom = Source::factory()->create([
            'slug' => 'side-room',
            'status' => SourceStatusEnum::ONLINE,
        ]);

        $this->assertTrue(Source::playable($this->source->slug));
        $this->assertFalse(Source::playable($sideRoom->slug));

        $this->get('/hls/side-room/master.m3u8?streamkey=viewer-key-123')->assertNotFound();
    }

    public function test_the_external_player_page_still_hands_out_streamkey_urls(): void
    {
        $show = Show::factory()->live()->create(['source_id' => $this->source->id]);

        $this->actingAs($this->viewer)
            ->get('/show/'.$show->slug.'/external')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('playlists', 4)
                ->where('playlists.0.url', fn ($url) => str_contains($url, 'streamkey='))
            );
    }

    // --------------------------------------------------------- error states

    public function test_a_bad_streamkey_is_a_401_even_on_an_open_channel(): void
    {
        Show::factory()->live()->create(['source_id' => $this->source->id]);

        // Identity is settled before the gate, so a broken credential says so rather
        // than being reported as a channel with nothing on it.
        $this->master('?streamkey=not-a-key')
            ->assertStatus(401)
            ->assertSee('Invalid streamkey');

        $this->variant('?streamkey=not-a-key')->assertStatus(401);
    }

    public function test_an_unknown_channel_is_a_404_that_says_so(): void
    {
        $this->get('/hls/no-such-channel/master.m3u8?streamkey=viewer-key-123')
            ->assertNotFound()
            ->assertSee('Stream not found');
    }

    public function test_a_closed_channel_is_a_404_that_says_which_404_it_is(): void
    {
        $this->master()
            ->assertNotFound()
            ->assertSee('No show on air');
    }

    public function test_a_closed_channel_costs_nothing_upstream(): void
    {
        // The gate runs before the edge is picked or called, so a wall of players
        // pointed at a dark channel cannot turn into upstream load.
        $this->master()->assertNotFound();
        $this->variant()->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_no_edge_is_a_503_not_a_closed_channel(): void
    {
        Show::factory()->live()->create(['source_id' => $this->source->id]);
        Server::query()->delete();
        Cache::flush();

        $this->master()
            ->assertStatus(503)
            ->assertSee('No server available');
    }

    public function test_an_edge_that_answers_badly_is_a_502(): void
    {
        Show::factory()->live()->create(['source_id' => $this->source->id]);

        $this->brokenEdge(500);

        $this->master()->assertStatus(502);
    }

    public function test_an_edge_that_does_not_answer_at_all_is_a_500_and_is_logged(): void
    {
        Show::factory()->live()->create(['source_id' => $this->source->id]);

        Log::spy();

        $this->brokenEdge(fn () => throw new ConnectionException('Connection refused'));

        // A connection that never lands is the app's problem to report, not a verdict
        // about the upstream, so it stays a 500 while a bad answer is a 502. Either
        // way it is written down with the stream and the edge that failed.
        $this->master()->assertStatus(500);

        Log::shouldHaveReceived('error')
            ->withArgs(fn (string $message, array $context) => $message === 'Failed to fetch playlist from edge server'
                && $context['stream'] === 'main-stage'
                && $context['server'] === 'edge.example.com'
                && str_contains($context['error'], 'Connection refused'));
    }

    public function test_an_edge_fault_is_papered_over_with_the_last_good_copy(): void
    {
        // What a main channel actually needs from a blip: the viewer keeps playing.
        Show::factory()->live()->create(['source_id' => $this->source->id]);

        $this->master()->assertOk()->assertHeader('X-Cache', 'MISS');

        // Past the one-second playlist TTL, so the next request has to go upstream.
        $this->travel(2)->seconds();

        $this->brokenEdge(500);

        $this->master()
            ->assertOk()
            ->assertHeader('X-Cache', 'STALE');
    }

    public function test_an_edge_404_passes_through_as_a_404_of_its_own(): void
    {
        // Between publisher reconnects the edge has no playlist to give. That is not
        // the gate talking, and it says something different.
        Show::factory()->live()->create(['source_id' => $this->source->id]);

        $this->brokenEdge(404);

        $this->master()
            ->assertNotFound()
            ->assertSee('Playlist not available')
            ->assertDontSee('No show on air');
    }

    public function test_an_invalid_variant_name_is_a_400(): void
    {
        Show::factory()->live()->create(['source_id' => $this->source->id]);

        $this->get('/hls/main-stage_4k.m3u8?streamkey=viewer-key-123')
            ->assertStatus(400)
            ->assertSee('Invalid variant format');
    }

    public function test_the_system_streamkey_is_not_a_way_into_an_unknown_channel(): void
    {
        // The gate exempts it; nothing else does.
        $this->get('/hls/no-such-channel/master.m3u8?streamkey=system-key-123')
            ->assertNotFound()
            ->assertSee('Stream not found');
    }
}
