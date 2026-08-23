<?php

namespace Tests\Feature\Manage;

use App\Models\Event;
use App\Models\Recording;
use App\Models\Show;
use App\Models\Source;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesManageUsers;
use Tests\TestCase;

/**
 * The calendar in the panel: the runs themselves, the backfill that files an archive
 * which predates them, and the two bulk actions that move a batch without opening a
 * form per row.
 */
class EventsTest extends TestCase
{
    use CreatesManageUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Event::forgetWindow();
        $this->createManageUsers();
    }

    private function recording(array $overrides = []): Recording
    {
        return Recording::create(array_merge([
            'title' => 'A cut',
            'date' => now(),
            'duration' => 3600,
            'is_published' => true,
            'status' => 'ready',
            'm3u8_url' => 'https://example.test/a.m3u8',
        ], $overrides));
    }

    public function test_the_list_says_what_is_on_and_what_is_next(): void
    {
        Event::create([
            'name' => 'This year',
            'starts_on' => now()->subDay(),
            'ends_on' => now()->addDay(),
        ]);
        Event::create([
            'name' => 'Next year',
            'starts_on' => now()->addYear(),
            'ends_on' => now()->addYear()->addDays(4),
        ]);
        Event::forgetWindow();

        $this->actingAs($this->admin)
            ->get(route('manage.events.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Manage/Events/Index')
                ->where('current.name', 'This year')
                ->where('next.name', 'Next year')
                ->has('table.rows', 2)
            );
    }

    public function test_an_event_gets_a_slug_from_its_name(): void
    {
        $this->actingAs($this->admin)
            ->post(route('manage.events.store'), [
                'name' => 'The Convention 30',
                'starts_on' => now()->toDateString(),
                'ends_on' => now()->addDays(4)->toDateString(),
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('events', ['slug' => 'the-convention-30']);
    }

    public function test_the_last_day_cannot_come_before_the_first(): void
    {
        $this->actingAs($this->admin)
            ->post(route('manage.events.store'), [
                'name' => 'Backwards',
                'starts_on' => now()->toDateString(),
                'ends_on' => now()->subDay()->toDateString(),
            ])
            ->assertSessionHasErrors('ends_on');
    }

    public function test_matching_by_date_files_only_what_is_unfiled(): void
    {
        $event = Event::create([
            'name' => 'This year',
            'starts_on' => now()->subDays(2),
            'ends_on' => now()->addDays(2),
        ]);
        $other = Event::create([
            'name' => 'Something else',
            'starts_on' => now()->subYear(),
            'ends_on' => now()->subYear()->addDay(),
        ]);
        Event::forgetWindow();

        $source = Source::factory()->create();

        // Created before the calendar existed, so nothing to inherit.
        $unfiled = Show::factory()->create([
            'source_id' => $source->id,
            'scheduled_start' => now(),
            'scheduled_end' => now()->addHour(),
            'event_id' => null,
        ]);

        // Filed elsewhere on purpose: an overlapping run must not steal it.
        $claimed = Show::factory()->create([
            'source_id' => $source->id,
            'scheduled_start' => now(),
            'scheduled_end' => now()->addHour(),
            'event_id' => $other->id,
        ]);

        $recording = $this->recording(['slug' => 'loose']);
        $outside = $this->recording(['slug' => 'outside', 'date' => now()->subMonths(3)]);

        $this->actingAs($this->admin)
            ->post(route('manage.events.match', $event))
            ->assertRedirect();

        $this->assertSame($event->id, $unfiled->fresh()->event_id);
        $this->assertSame($other->id, $claimed->fresh()->event_id);
        $this->assertSame($event->id, $recording->fresh()->event_id);
        $this->assertNull($outside->fresh()->event_id);
    }

    public function test_a_batch_of_shows_is_filed_at_once(): void
    {
        $event = Event::create([
            'name' => 'This year',
            'starts_on' => now(),
            'ends_on' => now()->addDays(3),
        ]);
        Event::forgetWindow();

        $source = Source::factory()->create();
        $shows = Show::factory()->count(3)->create(['source_id' => $source->id, 'event_id' => null]);

        $this->actingAs($this->admin)
            ->post(route('manage.shows.bulk.event'), [
                'ids' => $shows->pluck('id')->all(),
                'event_id' => $event->id,
            ])
            ->assertRedirect();

        $this->assertSame(3, Show::where('event_id', $event->id)->count());
    }

    public function test_a_batch_of_recordings_can_be_handed_back_to_their_shows(): void
    {
        $event = Event::create([
            'name' => 'This year',
            'starts_on' => now(),
            'ends_on' => now()->addDays(3),
        ]);
        Event::forgetWindow();

        $recording = $this->recording(['slug' => 'overridden', 'event_id' => $event->id]);

        $this->actingAs($this->admin)
            ->post(route('manage.recordings.bulk.event'), [
                'ids' => [$recording->id],
                'event_id' => null,
            ])
            ->assertRedirect();

        $this->assertNull($recording->fresh()->event_id);
    }

    public function test_deleting_an_event_unfiles_what_carried_it(): void
    {
        $event = Event::create([
            'name' => 'This year',
            'starts_on' => now(),
            'ends_on' => now()->addDays(3),
        ]);
        Event::forgetWindow();

        $source = Source::factory()->create();
        $show = Show::factory()->create(['source_id' => $source->id, 'event_id' => $event->id]);

        $this->actingAs($this->admin)
            ->delete(route('manage.events.destroy', $event))
            ->assertRedirect(route('manage.events.index'));

        // Nothing goes dark: the column is nullable and nothing reads it for access.
        $this->assertNull($show->fresh()->event_id);
        $this->assertDatabaseMissing('events', ['id' => $event->id]);
    }

    public function test_the_shows_list_opens_on_the_latest_run(): void
    {
        $current = Event::create([
            'name' => 'This year',
            'starts_on' => now()->subDay(),
            'ends_on' => now()->addWeeks(3),
        ]);
        $last = Event::create([
            'name' => 'Last year',
            'starts_on' => now()->subYear(),
            'ends_on' => now()->subYear()->addDays(4),
        ]);
        Event::forgetWindow();

        $source = Source::factory()->create();
        $onNow = Show::factory()->create(['source_id' => $source->id, 'event_id' => $current->id]);
        Show::factory()->create(['source_id' => $source->id, 'event_id' => $last->id]);
        // Before the calendar covered anything, so nothing files it on create.
        $unfiled = Show::factory()->create([
            'source_id' => $source->id,
            'event_id' => null,
            'scheduled_start' => now()->subYears(3),
            'scheduled_end' => now()->subYears(3)->addHour(),
        ]);

        $this->actingAs($this->admin)
            ->get(route('manage.shows.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('table.rows', 1)
                ->where('table.rows.0.id', $onNow->id));

        // Cleared back to everything, which is what an empty value in the query string
        // means: an absent one would only put the default back.
        $this->actingAs($this->admin)
            ->get(route('manage.shows.index', ['filter' => ['event' => '']]))
            ->assertInertia(fn (Assert $page) => $page->has('table.rows', 3));

        $this->actingAs($this->admin)
            ->get(route('manage.shows.index', ['filter' => ['event' => 'none']]))
            ->assertInertia(fn (Assert $page) => $page
                ->has('table.rows', 1)
                ->where('table.rows.0.id', $unfiled->id));
    }

    public function test_the_recordings_list_opens_on_the_latest_run(): void
    {
        $current = Event::create([
            'name' => 'This year',
            'starts_on' => now()->subDay(),
            'ends_on' => now()->addWeeks(3),
        ]);
        Event::create([
            'name' => 'Last year',
            'starts_on' => now()->subYear(),
            'ends_on' => now()->subYear()->addDays(4),
        ]);
        Event::forgetWindow();

        $source = Source::factory()->create();
        // Through its show rather than its own column, which is the case a plain
        // where('event_id') would miss.
        $show = Show::factory()->create(['source_id' => $source->id, 'event_id' => $current->id]);
        $inherited = $this->recording(['slug' => 'inherited', 'show_id' => $show->id]);
        $unfiled = $this->recording(['slug' => 'unfiled', 'date' => now()->subYears(3)]);

        $this->actingAs($this->admin)
            ->get(route('manage.recordings.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('table.rows', 1)
                ->where('table.rows.0.id', $inherited->id));

        $this->actingAs($this->admin)
            ->get(route('manage.recordings.index', ['filter' => ['event' => 'none']]))
            ->assertInertia(fn (Assert $page) => $page
                ->has('table.rows', 1)
                ->where('table.rows.0.id', $unfiled->id));

        $this->actingAs($this->admin)
            ->get(route('manage.recordings.index', ['filter' => ['event' => '']]))
            ->assertInertia(fn (Assert $page) => $page->has('table.rows', 2));
    }

    public function test_a_read_only_operator_cannot_change_the_calendar(): void
    {
        $event = Event::create([
            'name' => 'This year',
            'starts_on' => now(),
            'ends_on' => now()->addDays(3),
        ]);
        Event::forgetWindow();

        // Reading the calendar is open to anyone in the panel; setting it is not.
        $this->actingAs($this->moderator)
            ->get(route('manage.events.index'))
            ->assertOk();

        $this->actingAs($this->moderator)
            ->put(route('manage.events.update', $event), [
                'name' => 'Renamed',
                'starts_on' => now()->toDateString(),
                'ends_on' => now()->addDays(3)->toDateString(),
            ])
            ->assertForbidden();
    }
}
