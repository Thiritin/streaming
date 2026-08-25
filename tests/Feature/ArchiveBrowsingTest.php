<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Event;
use App\Models\Recording;
use App\Models\RecordingProgress;
use App\Models\Show;
use App\Models\Source;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * The public archive: shelves with nothing asked for, one grid once something is.
 *
 * The split is the thing worth pinning. Both modes render the same component, so a
 * regression that leaves the page in the wrong one is invisible from the route and
 * only shows up as an empty page for a viewer who clicked a chip.
 */
class ArchiveBrowsingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The browsing routes sit behind `auth.optional`, which is plain auth in the
     * test environment, so every one of these needs a viewer.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    private function recording(array $overrides = []): Recording
    {
        return Recording::create(array_merge([
            'title' => 'A show',
            'date' => now()->subDay(),
            'duration' => 3600,
            'views' => 10,
            'is_published' => true,
            'status' => 'ready',
            'm3u8_url' => 'https://example.test/a.m3u8',
        ], $overrides));
    }

    public function test_the_unfiltered_archive_is_one_grid_of_everything(): void
    {
        $this->recording(['title' => 'Opening ceremony', 'views' => 500]);
        $this->recording(['title' => 'Closing ceremony', 'date' => now()->subYears(2)]);

        $this->get(route('recordings.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Archive/Index')
                ->where('totalRecordings', 2)
                ->has('recordings', 2)
                ->has('chips.collections', 2)
                ->where('continueWatching', [])
            );
    }

    public function test_a_chip_collapses_the_page_to_one_grid(): void
    {
        $year = now()->subYears(2)->year;

        $this->recording(['title' => 'This year']);
        $this->recording(['title' => 'That year', 'date' => now()->subYears(2)]);

        $this->get(route('recordings.index', ['year' => $year]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Archive/Index')
                ->where('filters.year', $year)
                ->has('recordings', 1)
                ->where('recordings.0.title', 'That year')
            );
    }

    public function test_the_grid_sorts_and_no_longer_filters_by_source(): void
    {
        $stage = Source::factory()->create(['name' => 'Main Stage', 'slug' => 'main-stage']);
        $other = Source::factory()->create(['name' => 'Dance', 'slug' => 'dance']);

        $this->recording(['title' => 'Quiet panel', 'source_id' => $stage->id, 'views' => 5]);
        $this->recording(['title' => 'Packed panel', 'source_id' => $stage->id, 'views' => 900]);
        $this->recording(['title' => 'Elsewhere', 'source_id' => $other->id, 'views' => 4000]);

        // The source chips are gone, so a source in the URL narrows nothing: the
        // archive is filed by run and by what a show is, not by which room it came
        // out of.
        $this->get(route('recordings.index', ['source' => 'main-stage', 'sort' => 'views']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('recordings', 3)
                ->where('recordings.0.title', 'Elsewhere')
                ->missing('chips.sources')
            );
    }

    public function test_the_old_year_url_still_lands_on_the_year(): void
    {
        $this->get(route('recordings.year', 2024))
            ->assertRedirect(route('recordings.index', ['year' => 2024]));
    }

    public function test_suggestions_answer_json_and_need_two_characters(): void
    {
        $this->recording(['title' => 'Fursuit parade']);

        $this->getJson(route('recordings.suggest', ['q' => 'furs']))
            ->assertOk()
            ->assertJsonPath('suggestions.0.title', 'Fursuit parade');

        $this->getJson(route('recordings.suggest', ['q' => 'f']))
            ->assertOk()
            ->assertJsonPath('suggestions', []);
    }

    public function test_progress_is_recorded_for_a_signed_in_viewer_and_feeds_continue_watching(): void
    {
        $user = User::factory()->create();
        $recording = $this->recording(['title' => 'Half watched']);

        $this->actingAs($user)
            ->putJson(route('recordings.progress', $recording), ['position' => 1800])
            ->assertNoContent();

        $this->assertDatabaseHas('recording_progress', [
            'user_id' => $user->id,
            'recording_id' => $recording->id,
            'position' => 1800,
            'completed' => false,
        ]);

        $this->actingAs($user)
            ->get(route('recordings.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('continueWatching', 1)
                ->where('continueWatching.0.title', 'Half watched')
                ->where('continueWatching.0.progress.fraction', 0.5)
            );
    }

    public function test_a_finished_recording_leaves_continue_watching(): void
    {
        $user = User::factory()->create();
        $recording = $this->recording();

        $this->actingAs($user)
            ->putJson(route('recordings.progress', $recording), ['position' => 3600])
            ->assertNoContent();

        $this->assertTrue(RecordingProgress::first()->completed);

        $this->actingAs($user)
            ->get(route('recordings.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('continueWatching', [])
            );
    }

    public function test_a_guest_cannot_record_progress(): void
    {
        $recording = $this->recording();

        auth()->logout();

        $this->putJson(route('recordings.progress', $recording), ['position' => 60])
            ->assertUnauthorized();

        $this->assertDatabaseCount('recording_progress', 0);
    }

    public function test_the_player_offers_the_rest_of_the_same_source_next(): void
    {
        $stage = Source::factory()->create(['slug' => 'stage', 'name' => 'Stage']);
        $watching = $this->recording(['title' => 'Watching', 'source_id' => $stage->id]);
        $this->recording(['title' => 'Same stage', 'source_id' => $stage->id]);

        $this->get(route('recordings.show', $watching))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('RecordingPlayer')
                ->where('sourceName', 'Stage')
                ->has('upNext', 1)
                ->where('upNext.0.title', 'Same stage')
                ->where('resumeAt', 0)
            );
    }

    public function test_the_player_resumes_where_the_viewer_left_off(): void
    {
        $user = User::factory()->create();
        $recording = $this->recording();

        RecordingProgress::create([
            'user_id' => $user->id,
            'recording_id' => $recording->id,
            'position' => 900,
            'duration' => 3600,
        ]);

        $this->actingAs($user)
            ->get(route('recordings.show', $recording))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('resumeAt', 900));
    }

    public function test_a_recording_is_filed_under_its_show_category(): void
    {
        $dances = Category::factory()->create(['name' => 'Dances', 'slug' => 'dances']);
        $theatre = Category::factory()->create(['name' => 'Theatre', 'slug' => 'theatre']);

        $source = Source::factory()->create();
        $show = Show::factory()->create(['source_id' => $source->id, 'category_id' => $dances->id]);

        $this->recording(['title' => 'Opening dance', 'show_id' => $show->id]);
        $this->recording(['title' => 'A play', 'category_id' => $theatre->id]);

        $this->get(route('recordings.index', ['category' => 'dances']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('recordings', 1)
                ->where('recordings.0.title', 'Opening dance')
                ->where('recordings.0.category_name', 'Dances')
            );
    }

    public function test_a_recording_can_override_the_category_its_show_carries(): void
    {
        $dances = Category::factory()->create(['name' => 'Dances', 'slug' => 'dances']);
        $theatre = Category::factory()->create(['name' => 'Theatre', 'slug' => 'theatre']);

        $source = Source::factory()->create();
        $show = Show::factory()->create(['source_id' => $source->id, 'category_id' => $dances->id]);

        $this->recording([
            'title' => 'Actually a play',
            'show_id' => $show->id,
            'category_id' => $theatre->id,
        ]);

        $this->get(route('recordings.index', ['category' => 'dances']))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('recordings', 0));

        $this->get(route('recordings.index', ['category' => 'theatre']))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('recordings', 1)
                ->where('recordings.0.title', 'Actually a play')
            );
    }

    public function test_suggestions_lead_with_the_newest_run(): void
    {
        // The older cut has had a year longer to collect views, so views-first put it
        // above the one somebody typing the name is actually looking for.
        $this->recording([
            'title' => 'Paw Pet Show',
            'slug' => 'paw-pet-show-2025',
            'date' => now()->subYear(),
            'views' => 900,
        ]);
        $this->recording([
            'title' => 'Paw Pet Show',
            'slug' => 'paw-pet-show-2026',
            'date' => now()->subDay(),
            'views' => 12,
        ]);

        $response = $this->getJson(route('recordings.suggest', ['q' => 'paw pet']))->assertOk();

        $this->assertSame(
            [now()->subDay()->year, now()->subYear()->year],
            array_column($response->json('suggestions'), 'year'),
        );
    }

    public function test_a_run_and_a_category_narrow_together(): void
    {
        $competitions = Category::factory()->create(['name' => 'Competitions', 'slug' => 'competitions']);
        $source = Source::factory()->create();

        $thisRun = Event::create([
            'name' => 'EF30',
            'starts_on' => now()->subDays(3)->toDateString(),
            'ends_on' => now()->addDays(3)->toDateString(),
        ]);
        $lastRun = Event::create([
            'name' => 'EF29',
            'starts_on' => now()->subYear()->toDateString(),
            'ends_on' => now()->subYear()->addDays(6)->toDateString(),
        ]);
        Event::forgetWindow();

        // Both carry their run through their show, which is the case the archive is
        // actually made of and the one a plain where('event_id') would miss.
        $now = Show::factory()->create(['source_id' => $source->id, 'event_id' => $thisRun->id]);
        $then = Show::factory()->create(['source_id' => $source->id, 'event_id' => $lastRun->id]);

        $this->recording([
            'title' => 'Paws on Fire',
            'show_id' => $now->id,
            'category_id' => $competitions->id,
        ]);
        $this->recording([
            'title' => 'Last year competition',
            'show_id' => $then->id,
            'category_id' => $competitions->id,
        ]);

        $this->get(route('recordings.index', ['event' => $thisRun->slug, 'category' => 'competitions']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('recordings', 1)
                ->where('recordings.0.title', 'Paws on Fire')
            );

        // The id is as good an address as the slug, which is what the panel's own lists
        // carry.
        $this->get(route('recordings.index', ['event' => (string) $thisRun->id, 'category' => 'competitions']))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('recordings', 1));
    }

    public function test_a_category_chip_counts_inherited_recordings_too(): void
    {
        $dances = Category::factory()->create(['name' => 'Dances', 'slug' => 'dances']);
        $source = Source::factory()->create();
        $show = Show::factory()->create(['source_id' => $source->id, 'category_id' => $dances->id]);

        $this->recording(['title' => 'Through the show', 'show_id' => $show->id]);
        $this->recording(['title' => 'Labelled directly', 'category_id' => $dances->id]);
        $this->recording(['title' => 'Not a dance']);

        $this->get(route('recordings.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('chips.categories', 1)
                ->where('chips.categories.0.slug', 'dances')
                ->where('chips.categories.0.count', 2)
            );
    }

    public function test_category_chips_are_counted_against_the_run_that_is_selected(): void
    {
        $theater = Category::factory()->create(['name' => 'Theater', 'slug' => 'theater']);
        $source = Source::factory()->create();

        $thisRun = Event::create([
            'name' => 'EF30',
            'starts_on' => now()->subDays(3)->toDateString(),
            'ends_on' => now()->addDays(3)->toDateString(),
        ]);
        $lastRun = Event::create([
            'name' => 'EF29',
            'starts_on' => now()->subYear()->toDateString(),
            'ends_on' => now()->subYear()->addDays(6)->toDateString(),
        ]);
        Event::forgetWindow();

        $now = Show::factory()->create(['source_id' => $source->id, 'event_id' => $thisRun->id]);
        $then = Show::factory()->create(['source_id' => $source->id, 'event_id' => $lastRun->id]);

        $this->recording(['title' => 'This run', 'show_id' => $now->id, 'category_id' => $theater->id]);
        $this->recording(['title' => 'Last run', 'show_id' => $then->id, 'category_id' => $theater->id]);
        $this->recording(['title' => 'Also last run', 'show_id' => $then->id, 'category_id' => $theater->id]);

        $this->get(route('recordings.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('chips.categories.0.count', 3)
            );

        $this->get(route('recordings.index', ['event' => $thisRun->slug]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('chips.categories.0.count', 1)
            );
    }

    public function test_a_category_on_its_own_is_grouped_by_run(): void
    {
        $theatre = Category::factory()->create(['name' => 'Theater', 'slug' => 'theater']);
        $source = Source::factory()->create();

        $thisRun = Event::create([
            'name' => 'EF30',
            'starts_on' => now()->subDays(3)->toDateString(),
            'ends_on' => now()->addDays(3)->toDateString(),
        ]);
        $lastRun = Event::create([
            'name' => 'EF29',
            'starts_on' => now()->subYear()->toDateString(),
            'ends_on' => now()->subYear()->addDays(6)->toDateString(),
        ]);
        Event::forgetWindow();

        $now = Show::factory()->create(['source_id' => $source->id, 'event_id' => $thisRun->id]);
        $then = Show::factory()->create(['source_id' => $source->id, 'event_id' => $lastRun->id]);

        $this->recording(['title' => 'This run theatre', 'show_id' => $now->id, 'category_id' => $theatre->id]);
        $this->recording([
            'title' => 'Last run theatre',
            'show_id' => $then->id,
            'category_id' => $theatre->id,
            'date' => now()->subYear(),
        ]);
        $this->recording([
            'title' => 'Also last run',
            'show_id' => $then->id,
            'category_id' => $theatre->id,
            'date' => now()->subYear()->subDay(),
        ]);

        $this->get(route('recordings.index', ['category' => 'theater']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                // Newest run first, and its recordings lead the grid.
                ->where('groups.0.label', 'EF30')
                ->where('groups.0.count', 1)
                ->where('groups.1.label', 'EF29')
                ->where('groups.1.count', 2)
                ->where('recordings.0.title', 'This run theatre')
                ->where('recordings.0.event_label', 'EF30')
                ->where('recordings.1.event_label', 'EF29')
            );
    }

    public function test_picking_a_run_answers_the_question_so_the_grid_is_flat_again(): void
    {
        $theatre = Category::factory()->create(['name' => 'Theater', 'slug' => 'theater']);
        $source = Source::factory()->create();

        $run = Event::create([
            'name' => 'EF30',
            'starts_on' => now()->subDays(3)->toDateString(),
            'ends_on' => now()->addDays(3)->toDateString(),
        ]);
        Event::forgetWindow();

        $show = Show::factory()->create(['source_id' => $source->id, 'event_id' => $run->id]);
        $this->recording(['title' => 'Theatre', 'show_id' => $show->id, 'category_id' => $theatre->id]);

        $this->get(route('recordings.index', ['category' => 'theater', 'event' => $run->slug]))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('groups', []));

        // Nor in an order the grouping would argue with.
        $this->get(route('recordings.index', ['category' => 'theater', 'sort' => 'views']))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('groups', []));
    }
}
