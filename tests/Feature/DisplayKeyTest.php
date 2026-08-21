<?php

namespace Tests\Feature;

use App\Enum\ServerStatusEnum;
use App\Enum\ServerTypeEnum;
use App\Enum\SourceStatusEnum;
use App\Models\EmbedKey;
use App\Models\Server;
use App\Models\Show;
use App\Models\Source;
use App\Services\PlaybackTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DisplayKeyTest extends TestCase
{
    use RefreshDatabase;

    private EmbedKey $key;

    private string $plaintext;

    private Source $featured;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'stream.token.viewer_secret' => str_repeat('a', 64),
            'stream.token.embed_secret' => str_repeat('b', 64),
            'auth.required' => true,
        ]);

        $this->key = EmbedKey::generate('Hall 2 screen');
        $this->plaintext = $this->key->key;

        $this->featured = Source::factory()->create([
            'slug' => 'main-stage',
            'name' => 'Main Stage',
            'is_featured' => true,
            'status' => SourceStatusEnum::ONLINE,
        ]);

        // A screen may only open a channel that has a show on it.
        Show::factory()->live()->create(['source_id' => $this->featured->id]);

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
    }

    public function test_a_valid_key_is_exchanged_for_a_session_and_leaves_the_url(): void
    {
        $response = $this->get('/d/'.$this->plaintext);

        // The redirect is the point: the key must not stay in the address bar of a
        // screen standing in a public corridor.
        $response->assertRedirect(route('display.hub'));
        $this->assertSame($this->key->id, session('display_key_id'));
    }

    public function test_an_unknown_key_does_not_create_a_session(): void
    {
        $this->get('/d/AAAA-AAAA')
            ->assertRedirect(route('display.prompt'))
            ->assertSessionHasErrors('code');

        $this->assertNull(session('display_key_id'));
    }

    public function test_the_code_box_takes_a_typed_code(): void
    {
        $this->get('/d')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Display/Enter'));

        $this->post('/d', ['code' => strtolower($this->plaintext)])
            ->assertRedirect(route('display.hub'));

        $this->assertSame($this->key->id, session('display_key_id'));
    }

    public function test_a_wrong_code_in_the_box_says_so_without_leaving_the_page(): void
    {
        $this->from('/d')
            ->post('/d', ['code' => 'AAAA-AAAA'])
            ->assertRedirect('/d')
            ->assertSessionHasErrors('code');

        $this->assertNull(session('display_key_id'));
    }

    public function test_the_code_box_sends_a_set_up_screen_straight_to_the_hub(): void
    {
        $this->withSession(['display_key_id' => $this->key->id])
            ->get('/d')
            ->assertRedirect(route('display.hub'));
    }

    public function test_the_code_is_short_enough_to_type_and_free_of_ambiguous_characters(): void
    {
        // Someone reads this off a sheet into a TV remote. Nine characters with a
        // dash is the budget, and O/0 or I/1 guesswork is not affordable.
        $this->assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{4}-[0-9A-HJKMNP-TV-Z]{4}$/', $this->plaintext);
    }

    public function test_a_code_typed_loosely_still_works(): void
    {
        // Lowercase, no dash, a stray space, and the letters the alphabet dropped.
        $mangled = ' '.strtolower(str_replace(['0', '1'], ['O', 'I'], str_replace('-', '', $this->plaintext))).' ';

        $this->get('/d/'.rawurlencode($mangled))
            ->assertRedirect(route('display.hub'));

        $this->assertSame($this->key->id, session('display_key_id'));
    }

    public function test_guessing_codes_is_throttled(): void
    {
        // The whole reason 40 bits is enough. Without this, a short code is a toy.
        for ($i = 0; $i < 10; $i++) {
            $this->get('/d/AAAA-AAA'.$i)->assertRedirect(route('display.prompt'));
        }

        $this->get('/d/AAAA-AAAB')->assertStatus(429);

        // The box and the URL share one bucket, so alternating does not buy attempts.
        $this->post('/d', ['code' => 'AAAA-AAAB'])->assertStatus(429);
    }

    public function test_the_stored_hash_is_peppered_rather_than_a_bare_digest(): void
    {
        // A leaked table must not hand an attacker a 40-bit offline sweep.
        $this->assertNotSame(hash('sha256', $this->plaintext), $this->key->key_hash);
        $this->assertSame(
            hash_hmac('sha256', $this->plaintext, (string) config('app.key')),
            $this->key->key_hash,
        );
    }

    public function test_the_hub_is_locked_without_a_session(): void
    {
        $this->get('/display')->assertRedirect(route('display.prompt'));
    }

    public function test_the_hub_lists_every_source_with_playable_urls(): void
    {
        Source::factory()->create([
            'slug' => 'panel-room',
            'name' => 'Panel Room',
            'status' => SourceStatusEnum::OFFLINE,
        ]);

        $this->withSession(['display_key_id' => $this->key->id])
            ->get('/display')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Display/Hub')
                ->where('keyName', 'Hall 2 screen')
                ->has('sources', 2)
                // Featured sorts first so a screen starts on the main channel.
                ->where('sources.0.slug', 'main-stage')
                ->where('sources.0.isOnline', true)
                ->where('sources.1.isOnline', false)
                ->has('sources.0.renditions.hd')
            );
    }

    public function test_a_screen_can_sign_itself_out(): void
    {
        $this->withSession(['display_key_id' => $this->key->id])
            ->post('/display/leave')
            ->assertRedirect(route('display.prompt'));

        $this->assertNull(session('display_key_id'));
    }

    public function test_signing_out_screens_from_manage_drops_sessions_minted_before_it(): void
    {
        $session = [
            'display_key_id' => $this->key->id,
            'display_key_since' => now()->subMinute()->getTimestamp(),
        ];

        $this->key->signOutScreens();

        // The key still works; the session it already handed out does not.
        $this->withSession($session)->get('/display')->assertRedirect(route('display.prompt'));

        $this->get('/d/'.$this->plaintext)->assertRedirect(route('display.hub'));
        $this->get('/display')->assertOk();
    }

    public function test_signing_out_screens_kills_the_playback_tokens_they_are_holding(): void
    {
        Http::fake(fn () => Http::response("#EXTM3U\n", 200));

        $token = app(PlaybackTokenService::class)
            ->issueEmbed(keyId: (string) $this->key->id, source: $this->featured);

        $this->get('/hls/main-stage_hd.m3u8?t='.$token)->assertOk();

        // Embed tokens never expire, so without this a screen that walked off keeps
        // playing forever on the token it already has.
        $this->travel(2)->seconds();
        $this->key->signOutScreens();

        $this->get('/hls/main-stage_hd.m3u8?t='.$token)->assertStatus(401);

        // A token minted after the sign-out is fine.
        $fresh = app(PlaybackTokenService::class)
            ->issueEmbed(keyId: (string) $this->key->id, source: $this->featured);

        $this->get('/hls/main-stage_hd.m3u8?t='.$fresh)->assertOk();
    }

    public function test_revoking_the_key_locks_the_screen_out_immediately(): void
    {
        $session = ['display_key_id' => $this->key->id];

        $this->withSession($session)->get('/display')
            ->assertInertia(fn ($page) => $page->component('Display/Hub'));

        $this->key->delete();

        // The session still holds the id; the row is what authorises.
        $this->withSession($session)->get('/display')
            ->assertRedirect(route('display.prompt'));
    }

    public function test_the_kiosk_starts_on_the_featured_source_when_it_is_live(): void
    {
        $this->withSession(['display_key_id' => $this->key->id])
            ->get('/display/play')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Display/Play')
                ->where('initialSlug', 'main-stage')
            );
    }

    public function test_the_kiosk_falls_back_to_an_online_source_when_the_featured_one_is_down(): void
    {
        $this->featured->update(['status' => SourceStatusEnum::OFFLINE]);

        $panelRoom = Source::factory()->create([
            'slug' => 'panel-room',
            'name' => 'Panel Room',
            'status' => SourceStatusEnum::ONLINE,
        ]);

        Show::factory()->live()->create(['source_id' => $panelRoom->id]);

        $this->withSession(['display_key_id' => $this->key->id])
            ->get('/display/play')
            ->assertInertia(fn ($page) => $page->where('initialSlug', 'panel-room'));
    }

    public function test_an_embed_token_plays_without_an_account(): void
    {
        Http::fake(fn () => Http::response(
            "#EXTM3U\n#EXTINF:2.0,\nmain-stage_hd_1700000000_000001.ts\n",
            200
        ));

        $token = app(PlaybackTokenService::class)
            ->issueEmbed(keyId: (string) $this->key->id, source: $this->featured);

        // No session, no cookie, no streamkey: the token is the whole identity.
        $response = $this->get('/hls/main-stage_hd.m3u8?t='.$token);

        $response->assertOk();

        // Segments still get a short-lived viewer token, never the embed key itself,
        // so a leaked display URL cannot become a permanent segment credential.
        $body = $response->getContent();
        $this->assertStringContainsString('?t=v1.', $body);
        $this->assertStringNotContainsString($token, $body);
    }

    public function test_a_revoked_key_stops_playing(): void
    {
        Http::fake(fn () => Http::response("#EXTM3U\n", 200));

        $token = app(PlaybackTokenService::class)
            ->issueEmbed(keyId: (string) $this->key->id, source: $this->featured);

        $this->key->delete();

        // The token still verifies cryptographically; the kid no longer resolves.
        $this->get('/hls/main-stage_hd.m3u8?t='.$token)->assertStatus(401);
    }

    public function test_an_embed_token_for_one_source_does_not_open_another(): void
    {
        Http::fake(fn () => Http::response("#EXTM3U\n", 200));

        Source::factory()->create([
            'slug' => 'panel-room',
            'name' => 'Panel Room',
            'status' => SourceStatusEnum::ONLINE,
        ]);

        $token = app(PlaybackTokenService::class)
            ->issueEmbed(keyId: (string) $this->key->id, source: $this->featured);

        $this->get('/hls/panel-room_hd.m3u8?t='.$token)->assertStatus(401);
    }

    public function test_the_master_playlist_carries_the_token_into_its_variants(): void
    {
        Http::fake(fn () => Http::response(
            "#EXTM3U\n#EXT-X-STREAM-INF:BANDWIDTH=3500000\nmain-stage_hd.m3u8\n",
            200
        ));

        $token = app(PlaybackTokenService::class)
            ->issueEmbed(keyId: (string) $this->key->id, source: $this->featured);

        $body = $this->get('/hls/main-stage/master.m3u8?t='.$token)->getContent();

        // Dropping it here would 401 the very next request a player makes.
        $this->assertStringContainsString('/hls/main-stage_hd.m3u8?t='.$token, $body);
    }
}
