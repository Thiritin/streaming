<?php

namespace Tests\Feature;

use App\Enum\SourceStatusEnum;
use App\Models\DisplayScreen;
use App\Models\EmbedKey;
use App\Models\Source;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The screen half of display management: what a screen reports about itself, and
 * how it picks up an instruction sent from /manage.
 */
class DisplayScreenTest extends TestCase
{
    use RefreshDatabase;

    private EmbedKey $key;

    private string $plaintext;

    private Source $mainStage;

    private Source $panelRoom;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'stream.token.viewer_secret' => str_repeat('a', 64),
            'stream.token.embed_secret' => str_repeat('b', 64),
        ]);

        $this->key = EmbedKey::generate('Hall 2 screen');
        $this->plaintext = $this->key->key;

        $this->mainStage = Source::factory()->create([
            'slug' => 'main-stage',
            'name' => 'Main Stage',
            'is_featured' => true,
            'status' => SourceStatusEnum::ONLINE,
        ]);

        $this->panelRoom = Source::factory()->create([
            'slug' => 'panel-room',
            'name' => 'Panel Room',
            'status' => SourceStatusEnum::ONLINE,
        ]);
    }

    /**
     * A screen that has already been seen once.
     *
     * Sessions do not carry between test requests, so the session a real screen holds
     * is rebuilt by hand: the key it presented, and the row it was given.
     */
    private function screenSession(?DisplayScreen $screen = null): array
    {
        $screen ??= DisplayScreen::create([
            'embed_key_id' => $this->key->id,
            'page' => 'hub',
            'last_seen_at' => now(),
        ]);

        return [
            'display_key_id' => $this->key->id,
            DisplayScreen::SESSION_KEY => $screen->id,
        ];
    }

    public function test_a_polling_screen_records_what_it_is_playing(): void
    {
        $this->withSession(['display_key_id' => $this->key->id])
            ->getJson(route('display.state', ['page' => 'play', 'source' => 'panel-room']))
            ->assertOk()
            ->assertJsonPath('directedSlug', null);

        $screen = DisplayScreen::firstOrFail();

        $this->assertSame($this->key->id, $screen->embed_key_id);
        $this->assertSame($this->panelRoom->id, $screen->current_source_id);
        $this->assertSame('play', $screen->page);
        $this->assertTrue($screen->isPresent());
    }

    public function test_a_screen_keeps_one_row_across_polls(): void
    {
        $session = ['display_key_id' => $this->key->id];

        $this->withSession($session)
            ->getJson(route('display.state', ['page' => 'play', 'source' => 'main-stage']));

        $screen = DisplayScreen::firstOrFail();

        $this->withSession($this->screenSession($screen))
            ->getJson(route('display.state', ['page' => 'play', 'source' => 'panel-room']));

        $this->assertSame(1, DisplayScreen::count());
        $this->assertSame($this->panelRoom->id, $screen->fresh()->current_source_id);
    }

    public function test_the_hub_does_not_blank_what_the_screen_was_last_playing(): void
    {
        $session = $this->screenSession();

        $this->withSession($session)
            ->getJson(route('display.state', ['page' => 'play', 'source' => 'main-stage']));

        $this->withSession($session)->getJson(route('display.state', ['page' => 'hub']));

        $screen = DisplayScreen::firstOrFail();

        $this->assertSame('hub', $screen->page);
        $this->assertSame($this->mainStage->id, $screen->current_source_id);
    }

    public function test_a_directed_screen_is_told_where_to_go_and_clears_it_on_arrival(): void
    {
        $session = $this->screenSession();

        DisplayScreen::firstOrFail()->directTo($this->mainStage);

        $this->withSession($session)
            ->getJson(route('display.state', ['page' => 'play', 'source' => 'panel-room']))
            ->assertJsonPath('directedSlug', 'main-stage');

        // The screen reports it arrived, so the instruction is spent and whoever is
        // standing at the screen can switch channel again.
        $this->withSession($session)
            ->getJson(route('display.state', ['page' => 'play', 'source' => 'main-stage']))
            ->assertJsonPath('directedSlug', null);

        $this->assertNull(DisplayScreen::firstOrFail()->directed_source_id);
    }

    public function test_the_player_opens_on_the_directed_source_rather_than_the_url(): void
    {
        $session = $this->screenSession();

        DisplayScreen::firstOrFail()->directTo($this->mainStage);

        // A screen coming back from a reboot still has the old channel in its URL.
        $this->withSession($session)
            ->get(route('display.play', ['source' => 'panel-room']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Display/Play')
                ->where('initialSlug', 'main-stage')
            );
    }

    public function test_the_hub_carries_the_instruction_so_somebody_can_start_it(): void
    {
        $session = $this->screenSession();

        DisplayScreen::firstOrFail()->directTo($this->panelRoom);

        $this->withSession($session)
            ->get(route('display.hub'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Display/Hub')
                ->where('directedSlug', 'panel-room')
            );
    }

    public function test_state_without_a_session_records_nothing(): void
    {
        $this->getJson(route('display.state', ['page' => 'play', 'source' => 'main-stage']))
            ->assertStatus(401);

        $this->assertSame(0, DisplayScreen::count());
    }

    public function test_a_screen_signing_itself_out_stops_being_listed(): void
    {
        $session = $this->screenSession();
        $this->assertSame(1, DisplayScreen::count());

        $this->withSession($session)->post(route('display.leave'));

        $this->assertSame(0, DisplayScreen::count());
    }

    public function test_signing_out_a_key_from_manage_drops_its_screens(): void
    {
        $this->screenSession();

        $this->key->signOutScreens();

        $this->assertSame(0, DisplayScreen::count());
    }
}
