<?php

namespace Tests\Feature;

use App\Models\Category;
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

    public function test_the_grid_can_be_filtered_by_source_and_sorted(): void
    {
        $stage = Source::factory()->create(['name' => 'Main Stage', 'slug' => 'main-stage']);
        $other = Source::factory()->create(['name' => 'Dance', 'slug' => 'dance']);

        $this->recording(['title' => 'Quiet panel', 'source_id' => $stage->id, 'views' => 5]);
        $this->recording(['title' => 'Packed panel', 'source_id' => $stage->id, 'views' => 900]);
        $this->recording(['title' => 'Elsewhere', 'source_id' => $other->id, 'views' => 4000]);

        $this->get(route('recordings.index', ['source' => 'main-stage', 'sort' => 'views']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('recordings', 2)
                ->where('recordings.0.title', 'Packed panel')
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
}
