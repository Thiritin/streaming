<?php

namespace Tests\Feature\Manage;

use App\Models\Role;
use App\Models\Show;
use App\Models\Source;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Inertia\SessionKey;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesManageUsers;
use Tests\TestCase;

/**
 * Parity contract for the Shows module, transcribed from
 * docs/admin/current-filament-features.md 2.2.
 */
class ShowsTest extends TestCase
{
    use CreatesManageUsers;
    use RefreshDatabase;

    protected Source $source;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createManageUsers();

        // goLive() and endLivestream() broadcast; the transport is not what these assert.
        Event::fake();

        $this->source = Source::factory()->create(['name' => 'Main Stage']);
    }

    private function toast(): array
    {
        return session(SessionKey::FlashData->value)['toast'] ?? [];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function show(array $attributes = []): Show
    {
        return Show::factory()->create($attributes + [
            'source_id' => $this->source->id,
            'status' => 'scheduled',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Opening Ceremony',
            'slug' => 'opening-ceremony-2026-08-01',
            'source_id' => $this->source->id,
            'description' => 'Doors open',
            'scheduled_start' => '2026-08-01T10:00',
            'scheduled_end' => '2026-08-01T11:00',
            'actual_start' => null,
            'actual_end' => null,
            'auto_mode' => false,
            'auto_stop_at' => null,
            'recordable' => false,
            'visibility' => 'public',
            'required_roles' => [],
        ], $overrides);
    }

    // ---------------------------------------------------------------- access

    public function test_guests_are_sent_to_the_application_login(): void
    {
        $this->get(route('manage.shows.index'))->assertRedirect(route('login'));
    }

    public function test_a_user_without_the_gate_is_forbidden(): void
    {
        $this->actingAs($this->viewer)->get(route('manage.shows.index'))->assertForbidden();
    }

    // ---------------------------------------------------------------- list contract

    public function test_the_list_declares_every_column_the_filament_table_had(): void
    {
        $this->show();

        $this->actingAs($this->admin)
            ->get(route('manage.shows.index'))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Manage/Shows/Index')
                ->where('table.columns', fn ($columns) => collect($columns)->pluck('key')->all() === [
                    'thumbnail',
                    'title',
                    'source',
                    'status',
                    'scheduled_start',
                    'actual_start',
                    'viewer_count',
                    'peak_viewer_count',
                    'auto_mode',
                    'access',
                ])
            );
    }

    public function test_the_list_declares_every_filter_the_filament_table_had(): void
    {
        $this->actingAs($this->admin)
            ->get(route('manage.shows.index'))
            ->assertInertia(fn (Assert $page) => $page->where(
                'table.filters',
                fn ($filters) => collect($filters)->pluck('key')->all() === [
                    'hide_ended',
                    'status',
                    'source',
                    'today',
                    'upcoming',
                ],
            ));
    }

    public function test_ended_shows_are_hidden_by_default(): void
    {
        $this->show(['title' => 'Tonight', 'status' => 'scheduled']);
        $this->show(['title' => 'Yesterday', 'status' => 'ended']);

        $titles = fn (iterable $rows) => collect($rows)->pluck('cells.title')->all();

        // No query string at all: the filter's default has to apply, or the archive buries
        // today's running order.
        $this->actingAs($this->admin)
            ->get(route('manage.shows.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('table.filters.0.value', true)
                ->where('table.rows', fn ($rows) => $titles($rows) === ['Tonight'])
            );

        $this->actingAs($this->admin)
            ->get(route('manage.shows.index', ['filter' => ['hide_ended' => '0']]))
            ->assertInertia(fn (Assert $page) => $page->where(
                'table.rows',
                fn ($rows) => count($titles($rows)) === 2,
            ));
    }

    public function test_the_status_filter_accepts_several_states_at_once(): void
    {
        $this->show(['title' => 'Live now', 'status' => 'live']);
        $this->show(['title' => 'Cancelled one', 'status' => 'cancelled']);
        $this->show(['title' => 'Scheduled one', 'status' => 'scheduled']);

        $this->actingAs($this->admin)
            ->get(route('manage.shows.index', ['filter' => ['status' => ['live', 'cancelled']]]))
            ->assertInertia(fn (Assert $page) => $page->where(
                'table.rows',
                fn ($rows) => collect($rows)->pluck('cells.title')->sort()->values()->all() === [
                    'Cancelled one',
                    'Live now',
                ],
            ));
    }

    public function test_the_source_filter_narrows_the_list(): void
    {
        $other = Source::factory()->create(['name' => 'Stage B']);

        $this->show(['title' => 'On main']);
        $this->show(['title' => 'On B', 'source_id' => $other->id]);

        $this->actingAs($this->admin)
            ->get(route('manage.shows.index', ['filter' => ['source' => $other->id]]))
            ->assertInertia(fn (Assert $page) => $page->where(
                'table.rows',
                fn ($rows) => collect($rows)->pluck('cells.title')->all() === ['On B'],
            ));
    }

    public function test_the_default_sort_is_the_running_order(): void
    {
        $this->show(['title' => 'Later', 'scheduled_start' => now()->addHours(4)]);
        $this->show(['title' => 'Sooner', 'scheduled_start' => now()->addHour()]);

        $this->actingAs($this->admin)
            ->get(route('manage.shows.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('table.sort.key', 'scheduled_start')
                ->where('table.sort.dir', 'asc')
                ->where('table.rows', fn ($rows) => collect($rows)->pluck('cells.title')->all() === [
                    'Sooner',
                    'Later',
                ])
            );
    }

    public function test_a_row_reports_auto_mode_and_access_as_badges(): void
    {
        Role::create(['name' => 'Sponsor', 'slug' => 'sponsor', 'priority' => 40]);

        $this->show(['title' => 'Restricted', 'auto_mode' => true, 'required_roles' => ['sponsor']]);
        $this->show(['title' => 'Open', 'auto_mode' => false, 'required_roles' => []]);

        $this->actingAs($this->admin)
            ->get(route('manage.shows.index'))
            ->assertInertia(function (Assert $page) {
                $rows = collect($page->toArray()['props']['table']['rows'])->keyBy('cells.title');

                $this->assertSame('Auto', $rows['Restricted']['cells']['auto_mode']['label']);
                $this->assertSame('Restricted', $rows['Restricted']['cells']['access']['label']);
                $this->assertSame('warn', $rows['Restricted']['cells']['access']['tone']);

                $this->assertSame('Manual', $rows['Open']['cells']['auto_mode']['label']);
                $this->assertSame('Public', $rows['Open']['cells']['access']['label']);
            });
    }

    // ---------------------------------------------------------------- create and update

    public function test_creating_a_show(): void
    {
        $this->actingAs($this->admin)
            ->post(route('manage.shows.store'), $this->payload())
            ->assertRedirect();

        $show = Show::sole();

        $this->assertSame('Opening Ceremony', $show->title);
        $this->assertSame($this->source->id, $show->source_id);
        $this->assertSame('Show created', $this->toast()['title']);
    }

    public function test_the_scheduled_end_must_be_after_the_start(): void
    {
        $this->actingAs($this->admin)
            ->post(route('manage.shows.store'), $this->payload([
                'scheduled_start' => '2026-08-01T12:00',
                'scheduled_end' => '2026-08-01T11:00',
            ]))
            ->assertSessionHasErrors('scheduled_end');

        $this->assertSame(0, Show::count());
    }

    public function test_the_slug_must_be_unique(): void
    {
        $this->show(['slug' => 'taken']);

        $this->actingAs($this->admin)
            ->post(route('manage.shows.store'), $this->payload(['slug' => 'taken']))
            ->assertSessionHasErrors('slug');
    }

    public function test_a_required_role_must_exist(): void
    {
        $this->actingAs($this->admin)
            ->post(route('manage.shows.store'), $this->payload([
                'visibility' => 'private',
                'required_roles' => ['not-a-role'],
            ]))
            ->assertSessionHasErrors('required_roles.0');
    }

    // ---------------------------------------------------------------- access

    public function test_public_is_stored_as_an_empty_role_list(): void
    {
        Role::create(['name' => 'Sponsor', 'slug' => 'sponsor', 'priority' => 40]);

        $this->actingAs($this->admin)
            ->post(route('manage.shows.store'), $this->payload([
                'visibility' => 'public',
                // Ignored: a public show has no role list, whatever the form carried.
                'required_roles' => ['sponsor'],
            ]));

        $show = Show::sole();

        $this->assertSame([], $show->required_roles);
        $this->assertFalse($show->isPrivate());
        $this->assertTrue($show->canBeAccessedBy($this->viewer));
    }

    public function test_private_keeps_its_roles_and_shuts_everyone_else_out(): void
    {
        $sponsor = Role::create(['name' => 'Sponsor', 'slug' => 'sponsor', 'priority' => 40]);

        $this->actingAs($this->admin)
            ->post(route('manage.shows.store'), $this->payload([
                'visibility' => 'private',
                'required_roles' => ['sponsor'],
            ]));

        $show = Show::sole();

        $this->assertSame(['sponsor'], $show->required_roles);
        $this->assertTrue($show->isPrivate());
        $this->assertFalse($show->canBeAccessedBy($this->viewer));
        $this->assertFalse($show->canBeAccessedBy(null));

        $this->viewer->roles()->attach($sponsor);
        $this->assertTrue($show->fresh()->canBeAccessedBy($this->viewer->fresh()));
    }

    public function test_private_without_a_role_is_refused(): void
    {
        // A private show nobody can watch is a mistake, not a configuration.
        $this->actingAs($this->admin)
            ->post(route('manage.shows.store'), $this->payload([
                'visibility' => 'private',
                'required_roles' => [],
            ]))
            ->assertSessionHasErrors('required_roles');
    }

    public function test_the_form_reports_the_current_visibility(): void
    {
        Role::create(['name' => 'Sponsor', 'slug' => 'sponsor', 'priority' => 40]);
        $show = $this->show(['required_roles' => ['sponsor']]);

        $this->actingAs($this->admin)
            ->get(route('manage.shows.edit', $show))
            ->assertInertia(fn (Assert $page) => $page
                ->where('show.visibility', 'private')
                ->where('show.required_roles', ['sponsor'])
            );
    }

    // ---------------------------------------------------------------- auto mode

    public function test_the_hard_stop_defaults_to_the_scheduled_end(): void
    {
        $this->actingAs($this->admin)
            ->post(route('manage.shows.store'), $this->payload([
                'auto_mode' => true,
                'auto_stop_at' => null,
            ]));

        $show = Show::sole();

        $this->assertSame(
            $show->scheduled_end->format('Y-m-d H:i'),
            $show->auto_stop_at->format('Y-m-d H:i'),
        );
        $this->assertSame($show->auto_stop_at->format('Y-m-d H:i'), $show->autoStopAt()->format('Y-m-d H:i'));
    }

    public function test_an_explicit_hard_stop_can_run_past_the_scheduled_end(): void
    {
        // The dance case: the slot says 01:00, but keep recording until 02:00 at the latest.
        $this->actingAs($this->admin)
            ->post(route('manage.shows.store'), $this->payload([
                'auto_mode' => true,
                'scheduled_start' => '2026-08-01T22:00',
                'scheduled_end' => '2026-08-02T01:00',
                'auto_stop_at' => '2026-08-02T02:00',
            ]));

        $this->assertSame('2026-08-02 02:00', Show::sole()->auto_stop_at->format('Y-m-d H:i'));
    }

    public function test_the_hard_stop_is_cleared_when_auto_mode_is_switched_off(): void
    {
        $show = $this->show(['auto_mode' => true, 'auto_stop_at' => now()->addHour()]);

        $this->actingAs($this->admin)
            ->put(route('manage.shows.update', $show), $this->payload([
                'slug' => $show->slug,
                'auto_mode' => false,
                'auto_stop_at' => '2026-08-02T02:00',
            ]));

        $show->refresh();

        $this->assertFalse($show->auto_mode);
        $this->assertNull($show->auto_stop_at);
        // With auto mode off there is no hard stop to reach.
        $this->assertNull($show->autoStopAt());
        $this->assertFalse($show->isPastAutoStop());
    }

    public function test_the_form_cannot_move_the_status_at_all(): void
    {
        $show = $this->show(['status' => 'live']);

        $this->actingAs($this->admin)
            ->from(route('manage.shows.edit', $show))
            ->put(route('manage.shows.update', $show), $this->payload([
                'slug' => $show->slug,
                'title' => 'Renamed while live',
                // Not a field any more: every transition does more than write this column,
                // so it moves only through Go Live, End Stream and Cancel.
                'status' => 'ended',
            ]))
            ->assertRedirect(route('manage.shows.edit', $show));

        $show->refresh();

        $this->assertSame('Renamed while live', $show->title);
        $this->assertSame('live', $show->status);
    }

    public function test_cancelling_a_scheduled_show(): void
    {
        $show = $this->show(['status' => 'scheduled']);

        $this->actingAs($this->admin)
            ->from(route('manage.shows.edit', $show))
            ->post(route('manage.shows.cancel', $show))
            ->assertRedirect(route('manage.shows.edit', $show));

        $this->assertSame('cancelled', $show->fresh()->status);
        $this->assertSame('Show cancelled', $this->toast()['title']);
    }

    public function test_only_a_scheduled_show_can_be_cancelled(): void
    {
        foreach (['live', 'ended', 'cancelled'] as $status) {
            $show = $this->show(['status' => $status, 'slug' => "cancel-{$status}"]);

            $this->actingAs($this->admin)
                ->post(route('manage.shows.cancel', $show))
                ->assertForbidden();
        }
    }

    public function test_a_moderator_cannot_create_or_update_a_show(): void
    {
        $show = $this->show();

        $this->actingAs($this->moderator)
            ->post(route('manage.shows.store'), $this->payload())
            ->assertForbidden();

        $this->actingAs($this->moderator)
            ->put(route('manage.shows.update', $show), $this->payload(['slug' => $show->slug]))
            ->assertForbidden();
    }

    // ---------------------------------------------------------------- go live and end

    public function test_going_live_marks_the_show_and_stamps_the_start(): void
    {
        $show = $this->show();

        $this->actingAs($this->admin)
            ->from(route('manage.shows.index'))
            ->post(route('manage.shows.go-live', $show))
            ->assertRedirect(route('manage.shows.index'));

        $show->refresh();

        $this->assertSame('live', $show->status);
        $this->assertNotNull($show->actual_start);
        $this->assertSame('Show is now live!', $this->toast()['title']);
    }

    public function test_only_a_scheduled_show_can_go_live(): void
    {
        foreach (['live', 'ended', 'cancelled'] as $status) {
            $show = $this->show(['status' => $status, 'slug' => "slug-{$status}"]);

            $this->actingAs($this->admin)
                ->post(route('manage.shows.go-live', $show))
                ->assertForbidden();
        }
    }

    public function test_ending_a_stream_stamps_the_end(): void
    {
        $show = $this->show(['status' => 'live', 'actual_start' => now()->subHour()]);

        $this->actingAs($this->admin)
            ->from(route('manage.shows.index'))
            ->post(route('manage.shows.end', $show))
            ->assertRedirect(route('manage.shows.index'));

        $show->refresh();

        $this->assertSame('ended', $show->status);
        $this->assertNotNull($show->actual_end);
        $this->assertSame('Stream ended', $this->toast()['title']);
    }

    public function test_only_a_live_show_can_be_ended(): void
    {
        $show = $this->show(['status' => 'scheduled']);

        $this->actingAs($this->admin)
            ->post(route('manage.shows.end', $show))
            ->assertForbidden();
    }

    public function test_the_transition_actions_carry_the_right_icon_and_tone(): void
    {
        $this->show(['status' => 'scheduled']);

        $this->actingAs($this->admin)
            ->get(route('manage.shows.index'))
            ->assertInertia(function (Assert $page) {
                $actions = collect($page->toArray()['props']['table']['rows'][0]['actions'])->keyBy('name');

                $this->assertSame('play', $actions['go_live']['icon']);
                $this->assertSame('ok', $actions['go_live']['tone']);

                // Cancel is a scheduling decision, so it stays grey; red is reserved for
                // End Stream and Delete.
                $this->assertSame('circle-x', $actions['cancel']['icon']);
                $this->assertSame('idle', $actions['cancel']['tone']);
            });
    }

    public function test_go_live_is_only_offered_for_a_scheduled_show_and_end_only_while_live(): void
    {
        $scheduled = $this->show(['title' => 'Scheduled', 'slug' => 'a-scheduled']);
        $live = $this->show(['title' => 'Live', 'slug' => 'b-live', 'status' => 'live']);

        $this->actingAs($this->admin)
            ->get(route('manage.shows.index'))
            ->assertInertia(function (Assert $page) use ($scheduled, $live) {
                $rows = collect($page->toArray()['props']['table']['rows'])->keyBy('id');

                $names = fn ($id) => collect($rows[$id]['actions'])->pluck('name');

                $this->assertContains('go_live', $names($scheduled->id));
                $this->assertNotContains('end_stream', $names($scheduled->id));

                $this->assertContains('end_stream', $names($live->id));
                $this->assertNotContains('go_live', $names($live->id));
            });
    }

    public function test_the_screenshot_action_is_gone(): void
    {
        $this->show(['status' => 'live']);

        // The live thumbnail is captured off the stream; there is nothing to press.
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('manage.shows.screenshot'));

        $this->actingAs($this->admin)
            ->get(route('manage.shows.index'))
            ->assertInertia(function (Assert $page) {
                $names = collect($page->toArray()['props']['table']['rows'][0]['actions'])->pluck('name');

                $this->assertNotContains('capture_screenshot', $names);
            });
    }

    // ---------------------------------------------------------------- deletes

    public function test_a_live_show_cannot_be_deleted(): void
    {
        $show = $this->show(['status' => 'live']);

        $this->actingAs($this->admin)
            ->from(route('manage.shows.index'))
            ->delete(route('manage.shows.destroy', $show))
            ->assertRedirect(route('manage.shows.index'));

        $this->assertSame(1, Show::whereKey($show->id)->count());
        $this->assertSame([
            'tone' => 'danger',
            'title' => 'Cannot delete live show',
            'body' => 'Please end the stream before deleting.',
        ], $this->toast());
    }

    public function test_a_finished_show_can_be_deleted(): void
    {
        $show = $this->show(['status' => 'ended']);

        $this->actingAs($this->admin)
            ->delete(route('manage.shows.destroy', $show))
            ->assertRedirect(route('manage.shows.index'));

        $this->assertSame(0, Show::count());
    }

    public function test_one_live_show_blocks_the_whole_bulk_delete(): void
    {
        $safe = $this->show(['slug' => 'safe', 'status' => 'ended']);
        $live = $this->show(['slug' => 'live', 'status' => 'live']);

        $this->actingAs($this->admin)
            ->from(route('manage.shows.index'))
            ->delete(route('manage.shows.bulk.destroy'), ['ids' => [$safe->id, $live->id]])
            ->assertRedirect(route('manage.shows.index'));

        $this->assertSame(2, Show::count());
        $this->assertSame('Cannot delete shows', $this->toast()['title']);
    }

    public function test_bulk_cancel_only_touches_shows_that_have_not_started(): void
    {
        $scheduled = $this->show(['slug' => 'scheduled', 'status' => 'scheduled']);
        $live = $this->show(['slug' => 'live', 'status' => 'live']);

        $this->actingAs($this->admin)
            ->from(route('manage.shows.index'))
            ->post(route('manage.shows.bulk.cancel'), ['ids' => [$scheduled->id, $live->id]])
            ->assertRedirect(route('manage.shows.index'));

        $this->assertSame('cancelled', $scheduled->fresh()->status);
        // Skipped rather than failing the batch, as in Filament.
        $this->assertSame('live', $live->fresh()->status);
        $this->assertSame('Shows cancelled', $this->toast()['title']);
    }

    // ---------------------------------------------------------------- form options

    public function test_the_form_offers_sources_and_roles_and_nothing_else(): void
    {
        Role::create(['name' => 'Sponsor', 'slug' => 'sponsor', 'priority' => 40]);

        $this->actingAs($this->admin)
            ->get(route('manage.shows.create'))
            ->assertSuccessful()
            ->assertInertia(function (Assert $page) {
                $options = $page->toArray()['props']['options'];

                $this->assertSame([$this->source->id], collect($options['sources'])->pluck('value')->all());
                $this->assertContains('sponsor', collect($options['roles'])->pluck('value')->all());

                // Shows are no longer pinned to a server, and tags are gone for now.
                $this->assertArrayNotHasKey('servers', $options);
                $this->assertArrayNotHasKey('tagSuggestions', $options);
            });
    }

    public function test_a_live_show_keeps_its_slug_however_the_form_is_posted(): void
    {
        $show = $this->show(['status' => 'live', 'slug' => 'live-and-linked']);

        $this->actingAs($this->admin)
            ->put(route('manage.shows.update', $show), $this->payload([
                'slug' => 'renamed-mid-stream',
                'status' => 'live',
            ]));

        $this->assertSame('live-and-linked', $show->fresh()->slug);
    }

    public function test_the_recording_markers_keep_seconds(): void
    {
        $show = $this->show(['status' => 'ended']);

        $this->actingAs($this->admin)
            ->put(route('manage.shows.update', $show), $this->payload([
                'slug' => $show->slug,
                'status' => 'ended',
                'actual_start' => '2026-08-01T10:00:07',
                'actual_end' => '2026-08-01T11:02:41',
            ]));

        $show->refresh();

        $this->assertSame('2026-08-01 10:00:07', $show->actual_start->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-01 11:02:41', $show->actual_end->format('Y-m-d H:i:s'));

        // And they come back to the form with the seconds intact.
        $this->actingAs($this->admin)
            ->get(route('manage.shows.edit', $show))
            ->assertInertia(fn (Assert $page) => $page
                ->where('show.actual_start', '2026-08-01T10:00:07')
                ->where('show.actual_end', '2026-08-01T11:02:41')
            );
    }

    // ---------------------------------------------------------------- statistics

    public function test_the_statistics_report_charts_the_whole_broadcast(): void
    {
        $show = $this->show(['status' => 'ended', 'actual_start' => now()->subHour()]);

        // Peak, average and the chart come from the recorded samples, not the show row.
        foreach ([4, 12, 8] as $offset => $count) {
            \App\Models\ShowStatistic::create([
                'show_id' => $show->id,
                'viewer_count' => $count,
                'unique_viewers' => $count + 1,
                'recorded_at' => now()->subMinutes(30 - $offset),
            ]);
        }

        $this->actingAs($this->admin)
            ->get(route('manage.shows.statistics', $show))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Manage/Shows/Statistics')
                ->where('show.id', $show->id)
                ->where('report.peak', 12)
                ->where('report.average', 8)
                ->where('report.unique', 13)
                ->where('report.sampled_minutes', 3)
                ->count('report.chart', 3)
                ->where('report.chart.1.value', 12)
                // Nothing is live, so there is no live view to show.
                ->where('live', null)
            );
    }

    public function test_the_live_view_only_exists_while_the_show_is_running(): void
    {
        $show = $this->show(['status' => 'live', 'actual_start' => now()->subMinutes(10), 'viewer_count' => 9]);

        \App\Models\ShowStatistic::create([
            'show_id' => $show->id,
            'viewer_count' => 9,
            'unique_viewers' => 11,
            'recorded_at' => now()->subMinutes(2),
        ]);

        $this->actingAs($this->admin)
            ->get(route('manage.shows.statistics', $show))
            ->assertInertia(fn (Assert $page) => $page
                ->where('live.current', 9)
                ->where('live.peak', 9)
                ->has('live.sparkline')
                ->has('live.joins')
                ->has('live.leaves')
            );
    }

    public function test_the_chart_is_bucketed_rather_than_thinned_on_a_long_broadcast(): void
    {
        $show = $this->show(['status' => 'ended']);

        // 300 samples, five hours of minute ticks, against a 120 point cap.
        for ($minute = 0; $minute < 300; $minute++) {
            \App\Models\ShowStatistic::create([
                'show_id' => $show->id,
                'viewer_count' => 100,
                'unique_viewers' => 100,
                'recorded_at' => now()->subMinutes(300 - $minute),
            ]);
        }

        $this->actingAs($this->admin)
            ->get(route('manage.shows.statistics', $show))
            ->assertInertia(function (Assert $page) {
                $chart = $page->toArray()['props']['report']['chart'];

                $this->assertLessThanOrEqual(120, count($chart));
                // Averaged, not sampled: every bucket still reports the real level.
                $this->assertSame(100, $chart[0]['value']);
                // 300 minute-samples at 100 viewers each = 30 000 watch minutes = 500 hours.
                // assertEquals, not assertSame: a whole number of hours serializes as an int.
                $this->assertEquals(500, $page->toArray()['props']['report']['watch_hours']);
            });
    }

    public function test_a_moderator_may_read_a_show_but_is_offered_no_mutations(): void
    {
        $this->show();

        $this->actingAs($this->moderator)
            ->get(route('manage.shows.index'))
            ->assertSuccessful()
            ->assertInertia(function (Assert $page) {
                $table = $page->toArray()['props']['table'];

                $this->assertSame([], collect($table['pageActions'])->pluck('name')->all());
                $this->assertSame(
                    ['edit', 'statistics'],
                    collect($table['rows'][0]['actions'])->pluck('name')->all(),
                );
            });
    }
}
