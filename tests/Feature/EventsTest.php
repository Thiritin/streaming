<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Recording;
use App\Models\Show;
use App\Models\Source;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The convention calendar and the two things that read it.
 *
 * The one worth pinning hardest is the front page's mode: both modes render the same
 * component, so a regression that leaves it in the wrong one is invisible from the
 * route. The rule it must never break is that anything on air wins - an event window
 * being shut has to be a change of emphasis, never a stream that cannot be found.
 */
class EventsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Event::forgetWindow();
        $this->actingAs(User::factory()->create());
    }

    private function event(array $overrides = []): Event
    {
        $event = Event::create(array_merge([
            'name' => 'The Convention',
            'starts_on' => now()->subDay(),
            'ends_on' => now()->addDay(),
        ], $overrides));

        Event::forgetWindow();

        return $event;
    }

    private function recording(array $overrides = []): Recording
    {
        return Recording::create(array_merge([
            'title' => 'A cut',
            'date' => now()->subDay(),
            'duration' => 3600,
            'is_published' => true,
            'status' => 'ready',
            'm3u8_url' => 'https://example.test/a.m3u8',
        ], $overrides));
    }

    public function test_the_window_runs_to_the_end_of_the_closing_day(): void
    {
        $closingDay = today();

        $event = $this->event([
            'starts_on' => $closingDay->copy()->subDays(3)->toDateString(),
            'ends_on' => $closingDay->toDateString(),
        ]);

        // Not over on the closing morning, which is the off-by-one this exists to stop.
        $this->assertTrue($event->covers($closingDay->copy()->startOfDay()));
        $this->assertTrue($event->covers($closingDay->copy()->endOfDay()));
        $this->assertFalse($event->hasEnded());
        $this->assertTrue(Event::isLive());
    }

    public function test_a_new_show_inside_a_window_is_filed_under_it(): void
    {
        $event = $this->event();
        $source = Source::factory()->create();

        $inside = Show::create([
            'title' => 'Inside',
            'source_id' => $source->id,
            'scheduled_start' => now(),
            'scheduled_end' => now()->addHour(),
            'status' => 'scheduled',
        ]);

        $outside = Show::create([
            'title' => 'Outside',
            'source_id' => $source->id,
            'scheduled_start' => now()->addMonth(),
            'scheduled_end' => now()->addMonth()->addHour(),
            'status' => 'scheduled',
        ]);

        $this->assertSame($event->id, $inside->event_id);
        $this->assertNull($outside->event_id);
    }

    public function test_clearing_the_event_on_an_existing_show_sticks(): void
    {
        $event = $this->event();
        $source = Source::factory()->create();

        $show = Show::create([
            'title' => 'Filed',
            'source_id' => $source->id,
            'scheduled_start' => now(),
            'scheduled_end' => now()->addHour(),
            'status' => 'scheduled',
        ]);

        $this->assertSame($event->id, $show->event_id);

        // Guessing it back on every save would make the field impossible to empty.
        $show->update(['event_id' => null]);

        $this->assertNull($show->fresh()->event_id);
    }

    public function test_a_recording_inherits_its_shows_event_and_can_override_it(): void
    {
        $current = $this->event(['name' => 'This year']);
        $past = $this->event([
            'name' => 'Last year',
            'starts_on' => now()->subYear()->toDateString(),
            'ends_on' => now()->subYear()->addDays(3)->toDateString(),
        ]);

        $source = Source::factory()->create();
        $show = Show::factory()->create(['source_id' => $source->id, 'event_id' => $current->id]);

        $inherited = $this->recording(['show_id' => $show->id, 'slug' => 'inherited']);
        $overridden = $this->recording(['show_id' => $show->id, 'event_id' => $past->id, 'slug' => 'overridden']);

        $this->assertSame($current->id, $inherited->effectiveEvent()->id);
        $this->assertSame($past->id, $overridden->effectiveEvent()->id);

        // The scopes have to agree with the accessor, because the grid pages over them.
        $this->assertSame([$inherited->id], Recording::inEvent($current->slug)->pluck('id')->all());
        $this->assertSame([$overridden->id], Recording::notInEvent($current->slug)->pluck('id')->all());
    }

    public function test_the_archive_offers_a_chip_per_event_and_filters_on_it(): void
    {
        $current = $this->event(['name' => 'This year']);
        $past = $this->event([
            'name' => 'Last year',
            'starts_on' => now()->subYear()->toDateString(),
            'ends_on' => now()->subYear()->addDays(3)->toDateString(),
        ]);

        $this->recording(['title' => 'Recent', 'slug' => 'recent', 'event_id' => $current->id]);
        $this->recording([
            'title' => 'Older',
            'slug' => 'older',
            'event_id' => $past->id,
            'date' => now()->subYear(),
        ]);

        $this->get(route('recordings.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('chips.collections', 2)
                // Newest run first.
                ->where('chips.collections.0.label', 'This year')
                ->where('chips.collections.0.event', $current->slug)
                ->where('chips.collections.1.label', 'Last year')
            );

        $this->get(route('recordings.index', ['event' => $past->slug]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('recordings', 1)
                ->where('recordings.0.title', 'Older')
            );
    }

    public function test_a_recording_filed_under_no_event_keeps_a_year_chip(): void
    {
        $event = $this->event(['name' => 'This year']);

        $this->recording(['title' => 'Filed', 'slug' => 'filed', 'event_id' => $event->id]);
        $this->recording(['title' => 'Unfiled', 'slug' => 'unfiled', 'date' => now()->subYears(3)]);

        $year = now()->subYears(3)->year;

        $this->get(route('recordings.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('chips.collections', 2)
                // Events lead; the years left over follow them.
                ->where('chips.collections.0.event', $event->slug)
                ->where('chips.collections.1.label', (string) $year)
                ->where('chips.collections.1.year', $year)
                ->where('chips.collections.1.event', null)
            );

        $this->get(route('recordings.index', ['year' => $year]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('recordings', 1)
                ->where('recordings.0.title', 'Unfiled')
            );
    }

    public function test_the_front_page_is_a_programme_while_a_run_is_on(): void
    {
        $this->event();

        $this->get(route('shows.grid'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('ShowsGrid')
                ->where('archiveMode', false)
                ->where('event.name', 'The Convention')
            );
    }

    public function test_the_front_page_is_the_archive_between_runs(): void
    {
        $past = $this->event([
            'name' => 'Last year',
            'starts_on' => now()->subYear()->toDateString(),
            'ends_on' => now()->subYear()->addDays(3)->toDateString(),
        ]);

        $this->get(route('shows.grid'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('ShowsGrid')
                ->where('archiveMode', true)
                ->where('event', null)
                ->where('leadEvent.name', 'Last year')
            );

        $this->assertSame($past->id, Event::latestFinished()->id);
    }

    public function test_a_live_show_keeps_the_programme_even_between_runs(): void
    {
        $this->event([
            'name' => 'Last year',
            'starts_on' => now()->subYear()->toDateString(),
            'ends_on' => now()->subYear()->addDays(3)->toDateString(),
        ]);

        $source = Source::factory()->create();
        Show::factory()->create(['source_id' => $source->id, 'status' => 'live']);

        // The one rule this must never break: a stream on air is never hidden because
        // the calendar says the convention is over.
        $this->get(route('shows.grid'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('archiveMode', false));
    }

    public function test_with_no_calendar_the_front_page_keeps_its_old_shape(): void
    {
        $this->get(route('shows.grid'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('archiveMode', false)
                ->where('leadEvent', null)
            );
    }
}
