<?php

namespace Tests\Feature\Manage;

use App\Models\Category;
use App\Models\Recording;
use App\Models\Show;
use App\Models\Source;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesManageUsers;
use Tests\TestCase;

/**
 * Categories in the panel: the set itself, and the two bulk actions that make an
 * existing archive categorisable without opening a form per row.
 */
class CategoriesTest extends TestCase
{
    use CreatesManageUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

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

    public function test_the_list_shows_what_each_category_covers(): void
    {
        $category = Category::factory()->create(['name' => 'Dances', 'slug' => 'dances']);
        $source = Source::factory()->create();
        Show::factory()->create(['source_id' => $source->id, 'category_id' => $category->id]);

        $this->actingAs($this->admin)
            ->get(route('manage.categories.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Manage/Categories/Index')
                ->where('table.rows.0.cells.name', 'Dances')
                ->where('table.rows.0.cells.shows_count', 1)
            );
    }

    public function test_a_category_gets_a_slug_from_its_name(): void
    {
        $this->actingAs($this->admin)
            ->post(route('manage.categories.store'), ['name' => 'Musical performances'])
            ->assertRedirect(route('manage.categories.index'));

        $this->assertDatabaseHas('categories', [
            'name' => 'Musical performances',
            'slug' => 'musical-performances',
        ]);
    }

    public function test_deleting_a_category_leaves_its_shows_alone(): void
    {
        $category = Category::factory()->create();
        $source = Source::factory()->create();
        $show = Show::factory()->create(['source_id' => $source->id, 'category_id' => $category->id]);

        $this->actingAs($this->admin)
            ->delete(route('manage.categories.destroy', $category))
            ->assertRedirect(route('manage.categories.index'));

        $this->assertDatabaseHas('shows', ['id' => $show->id, 'category_id' => null]);
    }

    public function test_shows_can_be_categorised_in_bulk(): void
    {
        $category = Category::factory()->create();
        $source = Source::factory()->create();
        $shows = Show::factory()->count(3)->create(['source_id' => $source->id]);

        $this->actingAs($this->admin)
            ->post(route('manage.shows.bulk.category'), [
                'ids' => $shows->pluck('id')->all(),
                'category_id' => $category->id,
            ])
            ->assertRedirect();

        $this->assertSame(3, Show::where('category_id', $category->id)->count());
    }

    public function test_a_bulk_clear_hands_recordings_back_to_their_shows(): void
    {
        $category = Category::factory()->create();

        $recording = Recording::create([
            'title' => 'A cut',
            'date' => now(),
            'is_published' => true,
            'status' => 'ready',
            'm3u8_url' => 'https://example.test/a.m3u8',
            'category_id' => $category->id,
        ]);

        $this->actingAs($this->admin)
            ->post(route('manage.recordings.bulk.category'), [
                'ids' => [$recording->id],
                'category_id' => null,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('recordings', ['id' => $recording->id, 'category_id' => null]);
    }

    public function test_a_viewer_without_stream_manage_cannot_change_the_set(): void
    {
        $this->actingAs($this->moderator)
            ->post(route('manage.categories.store'), ['name' => 'Sneaky'])
            ->assertForbidden();
    }

    public function test_the_recordings_list_says_where_a_category_came_from(): void
    {
        $dances = Category::factory()->create(['name' => 'Dances', 'slug' => 'dances']);
        $theatre = Category::factory()->create(['name' => 'Theatre', 'slug' => 'theatre']);

        $source = Source::factory()->create();
        $show = Show::factory()->create(['source_id' => $source->id, 'category_id' => $dances->id]);

        $this->recording(['title' => 'Inherited', 'show_id' => $show->id, 'date' => now()->subDay()]);
        $this->recording(['title' => 'Overridden', 'category_id' => $theatre->id, 'date' => now()]);

        $this->actingAs($this->admin)
            ->get(route('manage.recordings.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Manage/Recordings/Index')
                // Newest first: the override leads, the inherited one follows and
                // says so rather than reading as set on the recording.
                ->where('table.rows.0.cells.category.label', 'Theatre')
                ->where('table.rows.1.cells.category.label', 'Dances (show)')
            );
    }

    public function test_the_recordings_list_filters_on_an_inherited_category(): void
    {
        $dances = Category::factory()->create(['name' => 'Dances', 'slug' => 'dances']);
        $source = Source::factory()->create();
        $show = Show::factory()->create(['source_id' => $source->id, 'category_id' => $dances->id]);

        $this->recording(['title' => 'Inherited', 'show_id' => $show->id]);
        $this->recording(['title' => 'Unrelated']);

        $this->actingAs($this->admin)
            ->get(route('manage.recordings.index', ['filter' => ['category' => 'dances']]))
            ->assertInertia(fn (Assert $page) => $page->where(
                'table.rows',
                fn ($rows) => collect($rows)->pluck('cells.title')->all() === ['Inherited'],
            ));
    }

    public function test_the_edit_page_does_not_offer_a_button_back_to_itself(): void
    {
        $category = Category::factory()->create();

        $this->actingAs($this->admin)
            ->get(route('manage.categories.edit', $category))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where(
                'actions',
                fn ($actions) => collect($actions)->pluck('name')->all() === ['delete'],
            ));
    }
}
