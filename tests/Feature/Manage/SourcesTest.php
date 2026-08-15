<?php

namespace Tests\Feature\Manage;

use App\Enum\SourceStatusEnum;
use App\Events\SourceStatusChangedEvent;
use App\Models\Server;
use App\Models\Show;
use App\Models\Source;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Inertia\SessionKey;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesManageUsers;
use Tests\TestCase;

/**
 * Parity contract for the Sources module, transcribed from
 * docs/admin/current-filament-features.md 2.1.
 */
class SourcesTest extends TestCase
{
    use CreatesManageUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createManageUsers();
    }

    private function toast(): array
    {
        return session(SessionKey::FlashData->value)['toast'] ?? [];
    }

    private function liveShowOn(Source $source): Show
    {
        return Show::factory()->create([
            'source_id' => $source->id,
            'status' => 'live',
        ]);
    }

    // ---------------------------------------------------------------- access

    public function test_guests_are_sent_to_the_application_login(): void
    {
        $this->get(route('manage.sources.index'))->assertRedirect(route('login'));
    }

    public function test_a_user_without_the_gate_is_forbidden(): void
    {
        $this->actingAs($this->viewer)->get(route('manage.sources.index'))->assertForbidden();
    }

    // ---------------------------------------------------------------- list contract

    public function test_the_list_declares_every_column_the_filament_table_had(): void
    {
        Source::factory()->create();

        $this->actingAs($this->admin)
            ->get(route('manage.sources.index'))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Manage/Sources/Index')
                ->where('table.columns', fn ($columns) => collect($columns)->pluck('key')->all() === [
                    'status',
                    'name',
                    'slug',
                    'priority',
                    'shows_count',
                    'live_shows_count',
                    'created_at',
                    'updated_at',
                ])
            );
    }

    public function test_the_timestamps_are_hidden_until_asked_for(): void
    {
        $this->actingAs($this->admin)
            ->get(route('manage.sources.index'))
            ->assertInertia(fn (Assert $page) => $page->where(
                'table.hiddenColumns',
                fn ($hidden) => collect($hidden)->all() === ['created_at', 'updated_at'],
            ));
    }

    public function test_the_list_sorts_by_priority_first(): void
    {
        Source::factory()->create(['name' => 'Second stage', 'priority' => 10]);
        Source::factory()->create(['name' => 'Main stage', 'priority' => 100]);

        $this->actingAs($this->admin)
            ->get(route('manage.sources.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('table.sort.key', 'priority')
                ->where('table.sort.dir', 'desc')
                ->where('table.rows', fn ($rows) => collect($rows)->pluck('cells.name')->all() === [
                    'Main stage',
                    'Second stage',
                ])
            );
    }

    public function test_the_status_filter_narrows_the_list(): void
    {
        Source::factory()->create(['name' => 'Live one', 'status' => SourceStatusEnum::ONLINE]);
        Source::factory()->create(['name' => 'Dark one', 'status' => SourceStatusEnum::OFFLINE]);

        $this->actingAs($this->admin)
            ->get(route('manage.sources.index', ['filter' => ['status' => 'online']]))
            ->assertInertia(fn (Assert $page) => $page->where(
                'table.rows',
                fn ($rows) => collect($rows)->pluck('cells.name')->all() === ['Live one'],
            ));
    }

    public function test_search_matches_name_and_stream_name(): void
    {
        Source::factory()->create(['name' => 'Main Stage', 'slug' => 'main-stage']);
        Source::factory()->create(['name' => 'Dealers Den', 'slug' => 'dealers']);

        $this->actingAs($this->admin)
            ->get(route('manage.sources.index', ['search' => 'dealers']))
            ->assertInertia(fn (Assert $page) => $page->count('table.rows', 1));

        $this->actingAs($this->admin)
            ->get(route('manage.sources.index', ['search' => 'main-stage']))
            ->assertInertia(fn (Assert $page) => $page->where(
                'table.rows',
                fn ($rows) => collect($rows)->pluck('cells.name')->all() === ['Main Stage'],
            ));
    }

    public function test_a_row_counts_its_shows_and_the_live_ones_separately(): void
    {
        $source = Source::factory()->create();
        $this->liveShowOn($source);
        Show::factory()->create(['source_id' => $source->id, 'status' => 'ended']);

        $this->actingAs($this->admin)
            ->get(route('manage.sources.index'))
            ->assertInertia(function (Assert $page) {
                $cells = $page->toArray()['props']['table']['rows'][0]['cells'];

                $this->assertSame(2, $cells['shows_count']);
                $this->assertSame('1', $cells['live_shows_count']['display']);
                $this->assertSame('on air', $cells['live_shows_count']['description']);
            });
    }

    // ---------------------------------------------------------------- create and update

    public function test_creating_a_source_generates_a_stream_key(): void
    {
        $this->actingAs($this->admin)
            ->post(route('manage.sources.store'), [
                'name' => 'Main Stage',
                'slug' => 'main-stage',
                'status' => SourceStatusEnum::OFFLINE->value,
                'priority' => 100,
                'description' => 'The big room',
            ])
            ->assertRedirect();

        $source = Source::where('slug', 'main-stage')->sole();

        $this->assertSame(100, $source->priority);
        $this->assertSame(SourceStatusEnum::OFFLINE, $source->status);
        // The model boot hook owns key generation; no form ever posts one.
        $this->assertNotEmpty($source->stream_key);
        $this->assertSame('Source created', $this->toast()['title']);
    }

    public function test_the_slug_must_be_unique(): void
    {
        Source::factory()->create(['slug' => 'main-stage']);

        $this->actingAs($this->admin)
            ->post(route('manage.sources.store'), [
                'name' => 'Another',
                'slug' => 'main-stage',
                'status' => SourceStatusEnum::OFFLINE->value,
                'priority' => 0,
            ])
            ->assertSessionHasErrors('slug');
    }

    public function test_a_source_keeps_its_own_slug_when_updated(): void
    {
        $source = Source::factory()->create(['slug' => 'main-stage']);

        $this->actingAs($this->admin)
            ->from(route('manage.sources.edit', $source))
            ->put(route('manage.sources.update', $source), [
                'name' => 'Main Stage Renamed',
                'priority' => 50,
            ])
            ->assertRedirect(route('manage.sources.edit', $source));

        $source->refresh();

        $this->assertSame('Main Stage Renamed', $source->name);
        $this->assertSame('main-stage', $source->slug);
        $this->assertSame(50, $source->priority);
    }

    /**
     * The slug is the RTMP ingress path and the HLS route key, so an edit that posts one
     * is ignored rather than honoured.
     */
    public function test_updating_a_source_cannot_move_its_slug(): void
    {
        $source = Source::factory()->create(['slug' => 'main-stage']);

        $this->actingAs($this->admin)
            ->from(route('manage.sources.edit', $source))
            ->put(route('manage.sources.update', $source), [
                'name' => 'Main Stage',
                'slug' => 'somewhere-else',
                'priority' => 0,
            ])
            ->assertRedirect(route('manage.sources.edit', $source));

        $this->assertSame('main-stage', $source->fresh()->slug);
    }

    /**
     * Status has exactly one path: the Update Status action. The edit form does not
     * carry it, and posting it there changes nothing.
     */
    public function test_updating_a_source_cannot_change_its_status(): void
    {
        $source = Source::factory()->create(['status' => SourceStatusEnum::OFFLINE]);

        $this->actingAs($this->admin)
            ->from(route('manage.sources.edit', $source))
            ->put(route('manage.sources.update', $source), [
                'name' => $source->name,
                'priority' => 0,
                'status' => SourceStatusEnum::ONLINE->value,
            ])
            ->assertRedirect(route('manage.sources.edit', $source));

        $this->assertSame(SourceStatusEnum::OFFLINE, $source->fresh()->status);
    }

    public function test_priority_is_bounded(): void
    {
        $this->actingAs($this->admin)
            ->post(route('manage.sources.store'), [
                'name' => 'Too keen',
                'slug' => 'too-keen',
                'status' => SourceStatusEnum::OFFLINE->value,
                'priority' => 1000,
            ])
            ->assertSessionHasErrors('priority');
    }

    public function test_a_moderator_cannot_create_or_update_a_source(): void
    {
        $source = Source::factory()->create();

        $this->actingAs($this->moderator)
            ->post(route('manage.sources.store'), [
                'name' => 'Nope',
                'slug' => 'nope',
                'status' => SourceStatusEnum::OFFLINE->value,
                'priority' => 0,
            ])
            ->assertForbidden();

        $this->actingAs($this->moderator)
            ->put(route('manage.sources.update', $source), [
                'name' => 'Nope',
                'slug' => $source->slug,
                'status' => SourceStatusEnum::OFFLINE->value,
                'priority' => 0,
            ])
            ->assertForbidden();
    }

    // ---------------------------------------------------------------- status actions

    public function test_updating_the_status_from_the_row_action(): void
    {
        // Named: a blanket fake would also fake Eloquent's model events and stop
        // SourceObserver from running, so the broadcast under test would never happen.
        Event::fake([SourceStatusChangedEvent::class]);

        $source = Source::factory()->create(['status' => SourceStatusEnum::OFFLINE, 'name' => 'Main Stage']);

        $this->actingAs($this->admin)
            ->from(route('manage.sources.index'))
            ->post(route('manage.sources.status', $source), ['status' => SourceStatusEnum::ERROR->value])
            ->assertRedirect(route('manage.sources.index'));

        $this->assertSame(SourceStatusEnum::ERROR, $source->fresh()->status);
        $this->assertSame(
            "Source 'Main Stage' status has been updated to error.",
            $this->toast()['body'],
        );
    }

    public function test_the_status_action_rejects_an_unknown_state(): void
    {
        $source = Source::factory()->create();

        $this->actingAs($this->admin)
            ->post(route('manage.sources.status', $source), ['status' => 'exploded'])
            ->assertSessionHasErrors('status');
    }

    public function test_bulk_status_updates_every_selected_source(): void
    {
        // Named: a blanket fake would also fake Eloquent's model events and stop
        // SourceObserver from running, so the broadcast under test would never happen.
        Event::fake([SourceStatusChangedEvent::class]);

        $first = Source::factory()->create(['status' => SourceStatusEnum::OFFLINE]);
        $second = Source::factory()->create(['status' => SourceStatusEnum::OFFLINE]);
        $untouched = Source::factory()->create(['status' => SourceStatusEnum::OFFLINE]);

        $this->actingAs($this->admin)
            ->from(route('manage.sources.index'))
            ->post(route('manage.sources.bulk.status'), [
                'ids' => [$first->id, $second->id],
                'status' => SourceStatusEnum::ONLINE->value,
            ])
            ->assertRedirect(route('manage.sources.index'));

        $this->assertSame(SourceStatusEnum::ONLINE, $first->fresh()->status);
        $this->assertSame(SourceStatusEnum::ONLINE, $second->fresh()->status);
        $this->assertSame(SourceStatusEnum::OFFLINE, $untouched->fresh()->status);
    }

    // ---------------------------------------------------------------- stream key

    public function test_regenerating_the_stream_key_replaces_it(): void
    {
        $source = Source::factory()->create();
        $before = $source->stream_key;

        $this->actingAs($this->admin)
            ->from(route('manage.sources.edit', $source))
            ->post(route('manage.sources.stream-key', $source))
            ->assertRedirect(route('manage.sources.edit', $source));

        $this->assertNotSame($before, $source->fresh()->stream_key);
        $this->assertSame('Stream key regenerated', $this->toast()['title']);
    }

    public function test_a_moderator_cannot_regenerate_a_stream_key(): void
    {
        $source = Source::factory()->create();

        $this->actingAs($this->moderator)
            ->post(route('manage.sources.stream-key', $source))
            ->assertForbidden();
    }

    // ---------------------------------------------------------------- deletes

    public function test_a_source_without_live_shows_can_be_deleted(): void
    {
        $source = Source::factory()->create();

        $this->actingAs($this->admin)
            ->delete(route('manage.sources.destroy', $source))
            ->assertRedirect(route('manage.sources.index'));

        $this->assertSame(0, Source::whereKey($source->id)->count());
        $this->assertSame('Source deleted', $this->toast()['title']);
    }

    public function test_a_source_with_a_live_show_is_refused_with_the_same_message_filament_used(): void
    {
        $source = Source::factory()->create();
        $this->liveShowOn($source);

        $this->actingAs($this->admin)
            ->from(route('manage.sources.index'))
            ->delete(route('manage.sources.destroy', $source))
            ->assertRedirect(route('manage.sources.index'));

        $this->assertSame(1, Source::whereKey($source->id)->count());
        $this->assertSame([
            'tone' => 'danger',
            'title' => 'Cannot delete source',
            'body' => 'This source has active live shows.',
        ], $this->toast());
    }

    public function test_a_table_row_only_offers_open_and_delete(): void
    {
        Source::factory()->create();

        $this->actingAs($this->admin)
            ->get(route('manage.sources.index'))
            ->assertInertia(function (Assert $page) {
                $names = collect($page->toArray()['props']['table']['rows'][0]['actions'])->pluck('name')->all();

                // Status overrides and key rotation act on one source and have consequences
                // past the row, so they live on the detail page, not in the table.
                $this->assertSame(['edit', 'delete'], $names);
            });
    }

    public function test_the_detail_page_is_where_status_and_key_rotation_live(): void
    {
        $source = Source::factory()->create();

        $this->actingAs($this->admin)
            ->get(route('manage.sources.edit', $source))
            ->assertInertia(function (Assert $page) {
                $names = collect($page->toArray()['props']['actions'])->pluck('name')->all();

                $this->assertSame(['update_status', 'regenerate_key', 'delete'], $names);
            });
    }

    public function test_the_delete_action_is_offered_but_disabled_while_a_show_is_live(): void
    {
        $source = Source::factory()->create();
        $this->liveShowOn($source);

        $this->actingAs($this->admin)
            ->get(route('manage.sources.index'))
            ->assertInertia(function (Assert $page) {
                $actions = collect($page->toArray()['props']['table']['rows'][0]['actions'])->keyBy('name');

                $this->assertSame(
                    'This source has active live shows.',
                    $actions['delete']['disabledReason'],
                );
            });
    }

    public function test_one_live_source_blocks_the_whole_bulk_delete(): void
    {
        $safe = Source::factory()->create();
        $live = Source::factory()->create();
        $this->liveShowOn($live);

        $this->actingAs($this->admin)
            ->from(route('manage.sources.index'))
            ->delete(route('manage.sources.bulk.destroy'), ['ids' => [$safe->id, $live->id]])
            ->assertRedirect(route('manage.sources.index'));

        // All or nothing, as in Filament.
        $this->assertSame(2, Source::count());
        $this->assertSame('Cannot delete sources', $this->toast()['title']);
    }

    public function test_bulk_delete_removes_the_selection_when_nothing_is_live(): void
    {
        $first = Source::factory()->create();
        $second = Source::factory()->create();
        $kept = Source::factory()->create();

        $this->actingAs($this->admin)
            ->from(route('manage.sources.index'))
            ->delete(route('manage.sources.bulk.destroy'), ['ids' => [$first->id, $second->id]]);

        $this->assertSame([$kept->id], Source::pluck('id')->all());
    }

    // ---------------------------------------------------------------- detail page

    public function test_the_obs_server_url_needs_an_active_origin(): void
    {
        $source = Source::factory()->create();

        // No origin: the field is empty rather than the page being a 500, which is what
        // reading ->hostname off null used to produce.
        $this->actingAs($this->admin)
            ->get(route('manage.sources.edit', $source))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page->where('source.rtmp_url', null));

        Server::factory()->origin()->create(['hostname' => 'origin.test']);

        $this->actingAs($this->admin)
            ->get(route('manage.sources.edit', $source))
            ->assertInertia(fn (Assert $page) => $page->where(
                'source.rtmp_url',
                'rtmp://origin.test:1935/ingress',
            ));
    }

    public function test_the_detail_page_exposes_the_obs_url_and_key_and_the_shows_on_the_source(): void
    {
        $source = Source::factory()->create();
        $show = Show::factory()->create(['source_id' => $source->id, 'title' => 'Opening Ceremony']);

        $this->actingAs($this->admin)
            ->get(route('manage.sources.edit', $source))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Manage/Sources/Form')
                ->where('source.rtmp_url', $source->getRtmpServerUrl())
                ->where('source.stream_key', $source->getObsStreamKey())
                ->count('shows', 1)
                ->where('shows.0.title', 'Opening Ceremony')
                ->where('shows.0.url', route('manage.shows.edit', $show))
            );
    }
}
