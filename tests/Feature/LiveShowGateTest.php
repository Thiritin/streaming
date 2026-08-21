<?php

namespace Tests\Feature;

use App\Enum\ServerStatusEnum;
use App\Enum\ServerTypeEnum;
use App\Enum\SourceStatusEnum;
use App\Models\EmbedKey;
use App\Models\Server;
use App\Models\Show;
use App\Models\Source;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesManageUsers;
use Tests\TestCase;

/**
 * A channel is watchable only while a show on it is live.
 *
 * Several channels ingest around the clock without being for anyone to watch - a
 * hall camera up through setup, a stage on colour bars - so a feed arriving must
 * not be what opens a channel, on the site or on a screen in a corridor.
 */
class LiveShowGateTest extends TestCase
{
    use CreatesManageUsers;
    use RefreshDatabase;

    private Source $source;

    private User $viewerAccount;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        config([
            'stream.token.viewer_secret' => str_repeat('a', 64),
            'stream.token.embed_secret' => str_repeat('b', 64),
            'stream.system_streamkey' => 'system-key-123',
        ]);

        $this->createManageUsers();

        $this->source = Source::factory()->create([
            'slug' => 'main-stage',
            'name' => 'Main Stage',
            'is_featured' => true,
            'status' => SourceStatusEnum::ONLINE,
        ]);

        $this->viewerAccount = User::factory()->create(['streamkey' => 'viewer-key-123']);

        Server::create([
            'hostname' => 'edge.example.com',
            'port' => 8080,
            'type' => ServerTypeEnum::EDGE,
            'status' => ServerStatusEnum::ACTIVE,
            'viewer_count' => 0,
            'max_clients' => 100,
            'hetzner_id' => 'test-edge',
            'ip' => '10.0.0.1',
        ]);

        Http::fake([
            '*_master.m3u8' => Http::response(
                "#EXTM3U\n#EXT-X-STREAM-INF:BANDWIDTH=3500000\nmain-stage_hd.m3u8\n", 200
            ),
            '*' => Http::response("#EXTM3U\n#EXTINF:2.0,\nmain-stage_hd_000001.ts\n", 200),
        ]);
    }

    private function goLive(): Show
    {
        return Show::factory()->live()->create(['source_id' => $this->source->id]);
    }

    // ------------------------------------------------------------- playlists

    public function test_a_channel_with_no_live_show_has_no_playlist(): void
    {
        $this->get('/hls/main-stage/master.m3u8?streamkey=viewer-key-123')
            ->assertNotFound();

        $this->get('/hls/main-stage_hd.m3u8?streamkey=viewer-key-123')
            ->assertNotFound();
    }

    public function test_the_same_channel_opens_once_a_show_goes_live(): void
    {
        $show = Show::factory()->create([
            'source_id' => $this->source->id,
            'status' => 'scheduled',
        ]);

        $this->get('/hls/main-stage/master.m3u8?streamkey=viewer-key-123')
            ->assertNotFound();

        // The playlist path answers from a short-lived cache; going live has to drop
        // it, or the channel would stay shut for the rest of the window.
        $show->goLive();

        $this->get('/hls/main-stage/master.m3u8?streamkey=viewer-key-123')
            ->assertOk();
    }

    public function test_the_channel_shuts_again_when_the_show_ends(): void
    {
        $show = $this->goLive();

        $this->get('/hls/main-stage/master.m3u8?streamkey=viewer-key-123')->assertOk();

        $show->endLivestream();

        $this->get('/hls/main-stage/master.m3u8?streamkey=viewer-key-123')
            ->assertNotFound();
    }

    public function test_a_signed_in_viewer_is_turned_down_the_same_way(): void
    {
        $this->actingAs($this->viewerAccount)
            ->get('/hls/main-stage/master.m3u8')
            ->assertNotFound();
    }

    public function test_the_system_streamkey_still_reaches_a_dark_channel(): void
    {
        // The thumbnailer and the archive uploader are not viewers, and are the
        // machinery a show needs in order to have gone live at all.
        $this->get('/hls/main-stage/master.m3u8?streamkey=system-key-123')
            ->assertOk();
    }

    public function test_an_operator_preview_still_reaches_a_dark_channel(): void
    {
        // Checking that a feed is arriving before the show is put live is the whole
        // point of preview.
        $this->actingAs($this->admin)
            ->get('/hls/main-stage/master.m3u8?preview=1')
            ->assertOk();
    }

    public function test_preview_is_not_a_way_past_the_gate_for_a_viewer(): void
    {
        $this->actingAs($this->viewerAccount)
            ->get('/hls/main-stage/master.m3u8?preview=1')
            ->assertNotFound();
    }

    // --------------------------------------------------------------- screens

    public function test_a_screen_is_given_no_token_for_a_channel_with_no_show(): void
    {
        $key = EmbedKey::generate('Hall 2 screen');

        $this->withSession(['display_key_id' => $key->id])
            ->get('/display')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Display/Hub')
                ->where('sources.0.isAvailable', false)
                ->where('sources.0.url', null)
                ->where('sources.0.renditions', null)
            );
    }

    public function test_a_screen_is_given_a_token_once_a_show_is_live(): void
    {
        $key = EmbedKey::generate('Hall 2 screen');
        $this->goLive();

        $this->withSession(['display_key_id' => $key->id])
            ->get('/display')
            ->assertInertia(fn (Assert $page) => $page
                ->where('sources.0.isAvailable', true)
                ->where('sources.0.url', fn ($url) => is_string($url) && str_contains($url, '?t='))
            );
    }

    public function test_the_kiosk_does_not_start_on_a_channel_with_no_show(): void
    {
        $key = EmbedKey::generate('Hall 2 screen');

        $panelRoom = Source::factory()->create([
            'slug' => 'panel-room',
            'name' => 'Panel Room',
            'status' => SourceStatusEnum::ONLINE,
        ]);

        Show::factory()->live()->create(['source_id' => $panelRoom->id]);

        // Featured, online, and ingesting - but nothing is on, so the screen starts
        // on the channel that does have a show.
        $this->withSession(['display_key_id' => $key->id])
            ->get('/display/play')
            ->assertInertia(fn (Assert $page) => $page->where('initialSlug', 'panel-room'));
    }

    public function test_the_kiosk_poll_moves_the_urls_when_a_show_ends(): void
    {
        $key = EmbedKey::generate('Hall 2 screen');
        $show = $this->goLive();

        $panelRoom = Source::factory()->create([
            'slug' => 'panel-room',
            'name' => 'Panel Room',
            'status' => SourceStatusEnum::ONLINE,
        ]);

        Show::factory()->live()->create(['source_id' => $panelRoom->id]);

        $polling = $this->withSession(['display_key_id' => $key->id]);

        $before = collect($polling->getJson('/display/state?page=play&source=main-stage')
            ->assertOk()
            ->json('sources'))->keyBy('slug');

        $this->assertTrue($before['main-stage']['isAvailable']);
        $this->assertStringContainsString('?t=', $before['main-stage']['url']);

        $show->endLivestream();

        // This is the poll a kiosk is sitting on: the channel it is playing loses its
        // URL in the same payload that still carries one for the channel it should
        // move to, which is what the switch is made from.
        $after = collect($polling->getJson('/display/state?page=play&source=main-stage')
            ->assertOk()
            ->json('sources'))->keyBy('slug');

        $this->assertFalse($after['main-stage']['isAvailable']);
        $this->assertNull($after['main-stage']['url']);
        $this->assertNull($after['main-stage']['renditions']);

        $this->assertTrue($after['panel-room']['isAvailable']);
        $this->assertStringContainsString('?t=', $after['panel-room']['url']);
        $this->assertStringContainsString('panel-room_hd', $after['panel-room']['renditions']['hd']);
    }

    public function test_the_url_a_screen_moves_to_actually_plays(): void
    {
        $key = EmbedKey::generate('Hall 2 screen');

        $panelRoom = Source::factory()->create([
            'slug' => 'panel-room',
            'name' => 'Panel Room',
            'status' => SourceStatusEnum::ONLINE,
        ]);

        Show::factory()->live()->create(['source_id' => $panelRoom->id]);

        $url = collect($this->withSession(['display_key_id' => $key->id])
            ->getJson('/display/state?page=play&source=main-stage')
            ->json('sources'))
            ->firstWhere('slug', 'panel-room')['url'];

        // A screen carries no session cookie into the player, only the token in the
        // URL, so the swap target has to open on the token alone.
        $this->get($url)->assertOk();
    }

    // ------------------------------------------------------------ show pages

    public function test_a_show_that_is_not_live_yet_mints_no_playback_token(): void
    {
        $show = Show::factory()->create([
            'source_id' => $this->source->id,
            'status' => 'scheduled',
            'scheduled_start' => now()->addMinutes(3),
        ]);

        $this->actingAs($this->viewerAccount)
            ->get('/show/'.$show->slug)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('ShowPlayer')
                ->where('playback', null)
                ->where('initialHlsUrl', null)
            );
    }

    public function test_a_live_show_mints_a_playback_token(): void
    {
        $show = $this->goLive();

        $this->actingAs($this->viewerAccount)
            ->get('/show/'.$show->slug)
            ->assertInertia(fn (Assert $page) => $page
                ->has('playback.token')
                ->where('initialHlsUrl', fn ($url) => is_string($url) && $url !== '')
            );
    }

    public function test_the_external_player_page_offers_no_urls_before_a_show_is_live(): void
    {
        $show = Show::factory()->create([
            'source_id' => $this->source->id,
            'status' => 'scheduled',
            'scheduled_start' => now()->addMinutes(3),
        ]);

        $this->actingAs($this->viewerAccount)
            ->get('/show/'.$show->slug.'/external')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('playlists', []));
    }
}
