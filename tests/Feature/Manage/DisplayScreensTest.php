<?php

namespace Tests\Feature\Manage;

use App\Enum\SourceStatusEnum;
use App\Models\DisplayScreen;
use App\Models\EmbedKey;
use App\Models\Source;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesManageUsers;
use Tests\TestCase;

/**
 * Steering screens from /manage: the half an operator uses when a show is about to
 * start and the screens are three floors away.
 */
class DisplayScreensTest extends TestCase
{
    use CreatesManageUsers;
    use RefreshDatabase;

    private EmbedKey $key;

    private Source $mainStage;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createManageUsers();

        $this->key = EmbedKey::generate('Hall 2 screen');

        $this->mainStage = Source::factory()->create([
            'slug' => 'main-stage',
            'name' => 'Main Stage',
            'status' => SourceStatusEnum::ONLINE,
        ]);
    }

    private function screen(array $attributes = []): DisplayScreen
    {
        return DisplayScreen::create($attributes + [
            'embed_key_id' => $this->key->id,
            'page' => 'play',
            'last_seen_at' => now(),
        ]);
    }

    public function test_the_list_shows_what_each_screen_is_playing(): void
    {
        $panelRoom = Source::factory()->create(['slug' => 'panel-room', 'name' => 'Panel Room']);

        $this->screen(['label' => 'Hall 2 left', 'current_source_id' => $panelRoom->id]);

        $page = $this->actingAs($this->admin)
            ->get(route('manage.displays.index'))
            ->assertOk()
            ->viewData('page');

        $cells = $page['props']['table']['rows'][0]['cells'];

        $this->assertSame('Hall 2 left', $cells['name']);
        $this->assertSame('Hall 2 screen', $cells['key_name']);
        $this->assertSame('Panel Room', $cells['playing']['label']);
        $this->assertSame('Live', $cells['presence']['label']);
    }

    public function test_a_screen_that_stopped_polling_is_filtered_out_by_default(): void
    {
        $this->screen(['label' => 'Live one']);
        $this->screen(['label' => 'Unplugged', 'last_seen_at' => now()->subHour()]);

        $page = $this->actingAs($this->admin)
            ->get(route('manage.displays.index'))
            ->viewData('page');

        $rows = collect($page['props']['table']['rows'])->pluck('cells.name');

        $this->assertSame(['Live one'], $rows->all());
    }

    public function test_a_screen_can_be_sent_to_a_source(): void
    {
        $screen = $this->screen();

        $this->actingAs($this->admin)
            ->post(route('manage.displays.direct', $screen), ['source_id' => $this->mainStage->id])
            ->assertRedirect();

        $this->assertSame($this->mainStage->id, $screen->fresh()->directed_source_id);
        $this->assertSame($this->admin->id, $screen->fresh()->directed_by);
    }

    public function test_an_instruction_can_be_withdrawn(): void
    {
        $screen = $this->screen(['directed_source_id' => $this->mainStage->id]);

        $this->actingAs($this->admin)
            ->post(route('manage.displays.direct', $screen), ['source_id' => ''])
            ->assertRedirect();

        $this->assertNull($screen->fresh()->directed_source_id);
    }

    public function test_every_polling_screen_can_be_sent_at_once(): void
    {
        $live = $this->screen();
        $gone = $this->screen(['last_seen_at' => now()->subHour()]);

        $this->actingAs($this->admin)
            ->post(route('manage.displays.direct-all'), ['source_id' => $this->mainStage->id])
            ->assertRedirect();

        $this->assertSame($this->mainStage->id, $live->fresh()->directed_source_id);

        // A screen that is not polling would never read the instruction, and leaving
        // one on it would move it the moment it came back hours later.
        $this->assertNull($gone->fresh()->directed_source_id);
    }

    public function test_a_selection_of_screens_can_be_sent(): void
    {
        $first = $this->screen();
        $second = $this->screen();
        $untouched = $this->screen();

        $this->actingAs($this->admin)
            ->post(route('manage.displays.bulk.direct'), [
                'ids' => [$first->id, $second->id],
                'source_id' => $this->mainStage->id,
            ])
            ->assertRedirect();

        $this->assertSame($this->mainStage->id, $first->fresh()->directed_source_id);
        $this->assertSame($this->mainStage->id, $second->fresh()->directed_source_id);
        $this->assertNull($untouched->fresh()->directed_source_id);
    }

    public function test_every_screen_on_one_key_can_be_sent_from_the_keys_table(): void
    {
        $first = $this->screen();
        $second = $this->screen();

        $other = EmbedKey::generate('Lobby');
        $elsewhere = DisplayScreen::create([
            'embed_key_id' => $other->id,
            'page' => 'play',
            'last_seen_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->post(route('manage.embed-keys.direct', $this->key), ['source_id' => $this->mainStage->id])
            ->assertRedirect();

        $this->assertSame($this->mainStage->id, $first->fresh()->directed_source_id);
        $this->assertSame($this->mainStage->id, $second->fresh()->directed_source_id);
        $this->assertNull($elsewhere->fresh()->directed_source_id);
    }

    public function test_a_screen_can_be_renamed(): void
    {
        $screen = $this->screen();

        $this->actingAs($this->admin)
            ->post(route('manage.displays.rename', $screen), ['label' => 'Hall 2 right'])
            ->assertRedirect();

        $this->assertSame('Hall 2 right', $screen->fresh()->label);
    }

    public function test_sending_a_screen_needs_the_manage_permission(): void
    {
        $screen = $this->screen();

        $this->actingAs($this->viewer)
            ->post(route('manage.displays.direct', $screen), ['source_id' => $this->mainStage->id])
            ->assertForbidden();

        $this->assertNull($screen->fresh()->directed_source_id);
    }
}
