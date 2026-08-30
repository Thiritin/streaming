<?php

namespace Tests\Feature\Manage;

use App\Models\Show;
use App\Models\Source;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\SessionKey;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesManageUsers;
use Tests\TestCase;

/**
 * The planner: one day's running order, sources as columns and hours down the side.
 */
class ShowPlannerTest extends TestCase
{
    use CreatesManageUsers;
    use RefreshDatabase;

    protected Source $main;

    protected Source $stageB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createManageUsers();

        $this->main = Source::factory()->create(['name' => 'Main Stage', 'priority' => 100]);
        $this->stageB = Source::factory()->create(['name' => 'Stage B', 'priority' => 10]);
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
            'source_id' => $this->main->id,
            'status' => 'scheduled',
        ]);
    }

    // ---------------------------------------------------------------- access

    public function test_guests_do_not_see_the_panel_at_all(): void
    {
        $this->get(route('manage.shows.planner'))->assertNotFound();
    }

    public function test_a_user_without_the_gate_is_forbidden(): void
    {
        $this->actingAs($this->viewer)->get(route('manage.shows.planner'))->assertForbidden();
    }

    public function test_a_moderator_gets_a_read_only_planner(): void
    {
        $this->actingAs($this->moderator)
            ->get(route('manage.shows.planner'))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page->where('can.edit', false));
    }

    // ---------------------------------------------------------------- columns and day

    public function test_every_source_gets_a_column_in_priority_order(): void
    {
        $this->actingAs($this->admin)
            ->get(route('manage.shows.planner'))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Manage/Shows/Planner')
                ->where('columns', fn ($columns) => collect($columns)->pluck('name')->all() === [
                    'Main Stage',
                    'Stage B',
                ])
                ->where('can.edit', true)
            );
    }

    public function test_a_column_carries_only_its_own_shows(): void
    {
        $this->show(['title' => 'On main', 'scheduled_start' => now()->addHour(), 'scheduled_end' => now()->addHours(2)]);
        $this->show([
            'title' => 'On B',
            'source_id' => $this->stageB->id,
            'scheduled_start' => now()->addHour(),
            'scheduled_end' => now()->addHours(2),
        ]);

        $this->actingAs($this->admin)
            ->get(route('manage.shows.planner'))
            ->assertInertia(function (Assert $page) {
                $columns = collect($page->toArray()['props']['columns'])->keyBy('name');

                $this->assertSame(['On main'], collect($columns['Main Stage']['shows'])->pluck('title')->all());
                $this->assertSame(['On B'], collect($columns['Stage B']['shows'])->pluck('title')->all());
            });
    }

    public function test_the_planner_opens_on_today(): void
    {
        $this->actingAs($this->admin)
            ->get(route('manage.shows.planner'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('day.iso', now()->toDateString())
                ->where('day.isToday', true)
                ->where('day.previous', now()->subDay()->toDateString())
                ->where('day.next', now()->addDay()->toDateString())
                ->where('closeUrl', route('manage.shows.index'))
            );
    }

    public function test_another_day_can_be_asked_for(): void
    {
        $this->actingAs($this->admin)
            ->get(route('manage.shows.planner', ['date' => '2026-08-01']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('day.iso', '2026-08-01')
                ->where('day.isToday', false)
            );
    }

    public function test_a_broken_date_parameter_falls_back_to_today(): void
    {
        $this->actingAs($this->admin)
            ->get(route('manage.shows.planner', ['date' => 'last thursday-ish']))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page->where('day.isToday', true));
    }

    /**
     * A day with nothing on it still has to be usable, so the grid opens on the hours
     * a show is most likely to be placed in rather than on midnight.
     */
    public function test_an_empty_day_opens_on_working_hours(): void
    {
        $this->actingAs($this->admin)
            ->get(route('manage.shows.planner', ['date' => '2026-08-01']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('hours.from', 8)
                ->where('hours.to', 24)
            );
    }

    public function test_the_hour_window_opens_around_the_day_that_is_programmed(): void
    {
        $this->show([
            'scheduled_start' => '2026-08-01 14:00:00',
            'scheduled_end' => '2026-08-01 16:00:00',
        ]);

        // An hour of air either side of the programme, so a block can be dragged out
        // of the window's edge without switching to the full day first.
        $this->actingAs($this->admin)
            ->get(route('manage.shows.planner', ['date' => '2026-08-01']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('hours.from', 13)
                ->where('hours.to', 17)
            );
    }

    public function test_a_show_running_past_midnight_still_appears_on_the_day_it_starts(): void
    {
        // The dance case: starts inside the day, ends after it. Containment would drop it.
        $this->show([
            'title' => 'Overnight dance',
            'scheduled_start' => '2026-08-01 22:00:00',
            'scheduled_end' => '2026-08-02 02:00:00',
        ]);

        $this->actingAs($this->admin)
            ->get(route('manage.shows.planner', ['date' => '2026-08-01']))
            ->assertInertia(fn (Assert $page) => $page->where(
                'columns.0.shows',
                fn ($shows) => collect($shows)->pluck('title')->all() === ['Overnight dance'],
            ));
    }

    public function test_shows_on_another_day_are_left_out(): void
    {
        $this->show([
            'title' => 'Next month',
            'scheduled_start' => now()->addDays(40),
            'scheduled_end' => now()->addDays(40)->addHour(),
        ]);

        $this->actingAs($this->admin)
            ->get(route('manage.shows.planner'))
            ->assertInertia(fn (Assert $page) => $page->count('columns.0.shows', 0));
    }

    public function test_a_live_block_is_locked(): void
    {
        $this->show(['title' => 'On air', 'status' => 'live', 'scheduled_start' => now(), 'scheduled_end' => now()->addHour()]);

        $this->actingAs($this->admin)
            ->get(route('manage.shows.planner'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('columns.0.shows.0.locked', true)
                ->where('columns.0.shows.0.status.label', 'Live')
            );
    }

    public function test_the_shows_list_is_what_offers_the_planner(): void
    {
        $this->actingAs($this->admin)
            ->get(route('manage.shows.index'))
            ->assertInertia(fn (Assert $page) => $page->where(
                'table.pageActions',
                fn ($actions) => collect($actions)->firstWhere('name', 'planner')['url']
                    === route('manage.shows.planner'),
            ));
    }

    // ---------------------------------------------------------------- rescheduling

    public function test_dragging_a_block_moves_both_edges(): void
    {
        $show = $this->show([
            'scheduled_start' => '2026-08-01 10:00:00',
            'scheduled_end' => '2026-08-01 11:00:00',
        ]);

        $this->actingAs($this->admin)
            ->from(route('manage.shows.planner'))
            ->patch(route('manage.shows.reschedule', $show), [
                'scheduled_start' => '2026-08-01T14:00:00',
                'scheduled_end' => '2026-08-01T15:00:00',
            ])
            ->assertRedirect(route('manage.shows.planner'));

        $show->refresh();

        $this->assertSame('2026-08-01 14:00', $show->scheduled_start->format('Y-m-d H:i'));
        $this->assertSame('2026-08-01 15:00', $show->scheduled_end->format('Y-m-d H:i'));
        $this->assertSame('Show rescheduled', $this->toast()['title']);
    }

    public function test_resizing_only_moves_the_end(): void
    {
        $show = $this->show([
            'scheduled_start' => '2026-08-01 10:00:00',
            'scheduled_end' => '2026-08-01 11:00:00',
        ]);

        $this->actingAs($this->admin)
            ->patch(route('manage.shows.reschedule', $show), [
                'scheduled_start' => '2026-08-01T10:00:00',
                'scheduled_end' => '2026-08-01T12:30:00',
            ]);

        $show->refresh();

        $this->assertSame('2026-08-01 10:00', $show->scheduled_start->format('Y-m-d H:i'));
        $this->assertSame('2026-08-01 12:30', $show->scheduled_end->format('Y-m-d H:i'));
    }

    public function test_rescheduling_leaves_everything_that_is_not_a_time_alone(): void
    {
        $show = $this->show([
            'title' => 'Keep me',
            'auto_mode' => true,
            'publish_plan' => 'yes',
            'required_roles' => [],
            'scheduled_start' => '2026-08-01 10:00:00',
            'scheduled_end' => '2026-08-01 11:00:00',
        ]);

        $this->actingAs($this->admin)
            ->patch(route('manage.shows.reschedule', $show), [
                'scheduled_start' => '2026-08-01T12:00:00',
                'scheduled_end' => '2026-08-01T13:00:00',
                // Not accepted by this endpoint; the planner only moves time.
                'title' => 'Renamed by a drag',
                'status' => 'ended',
            ]);

        $show->refresh();

        $this->assertSame('Keep me', $show->title);
        $this->assertSame('scheduled', $show->status);
        $this->assertTrue($show->auto_mode);
        $this->assertSame('yes', $show->publish_plan);
    }

    public function test_an_end_before_the_start_is_refused(): void
    {
        $show = $this->show([
            'scheduled_start' => '2026-08-01 10:00:00',
            'scheduled_end' => '2026-08-01 11:00:00',
        ]);

        $this->actingAs($this->admin)
            ->patch(route('manage.shows.reschedule', $show), [
                'scheduled_start' => '2026-08-01T14:00:00',
                'scheduled_end' => '2026-08-01T13:00:00',
            ])
            ->assertSessionHasErrors('scheduled_end');

        $this->assertSame('2026-08-01 10:00', $show->fresh()->scheduled_start->format('Y-m-d H:i'));
    }

    public function test_a_moderator_cannot_reschedule(): void
    {
        $show = $this->show([
            'scheduled_start' => '2026-08-01 10:00:00',
            'scheduled_end' => '2026-08-01 11:00:00',
        ]);

        $this->actingAs($this->moderator)
            ->patch(route('manage.shows.reschedule', $show), [
                'scheduled_start' => '2026-08-01T14:00:00',
                'scheduled_end' => '2026-08-01T15:00:00',
            ])
            ->assertForbidden();
    }

    // ---------------------------------------------------------------- quick create

    public function test_quick_create_holds_a_slot_with_sensible_defaults(): void
    {
        $this->actingAs($this->admin)
            ->from(route('manage.shows.planner'))
            ->post(route('manage.shows.planner.store'), [
                'title' => 'Fursuit Parade',
                'source_id' => $this->stageB->id,
                'scheduled_start' => '2026-08-01T14:00:00',
                'scheduled_end' => '2026-08-01T15:30:00',
            ])
            ->assertRedirect(route('manage.shows.planner'));

        $show = Show::sole();

        $this->assertSame('Fursuit Parade', $show->title);
        $this->assertSame($this->stageB->id, $show->source_id);
        $this->assertSame('scheduled', $show->status);
        $this->assertFalse($show->auto_mode);
        // Public until someone decides otherwise on the form.
        $this->assertSame([], $show->required_roles);
        // The model builds the dated slug from title and start.
        $this->assertSame('fursuit-parade-2026-08-01', $show->slug);
        $this->assertSame('Show created', $this->toast()['title']);
    }

    public function test_quick_create_needs_a_title_and_a_real_source(): void
    {
        $this->actingAs($this->admin)
            ->post(route('manage.shows.planner.store'), [
                'title' => '',
                'source_id' => 99999,
                'scheduled_start' => '2026-08-01T14:00:00',
                'scheduled_end' => '2026-08-01T15:30:00',
            ])
            ->assertSessionHasErrors(['title', 'source_id']);

        $this->assertSame(0, Show::count());
    }

    public function test_a_moderator_cannot_quick_create(): void
    {
        $this->actingAs($this->moderator)
            ->post(route('manage.shows.planner.store'), [
                'title' => 'Nope',
                'source_id' => $this->main->id,
                'scheduled_start' => '2026-08-01T14:00:00',
                'scheduled_end' => '2026-08-01T15:00:00',
            ])
            ->assertForbidden();

        $this->assertSame(0, Show::count());
    }
}
