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
 * The planner: the running order as one track per source across the event.
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

    public function test_guests_are_sent_to_the_application_login(): void
    {
        $this->get(route('manage.shows.planner'))->assertRedirect(route('login'));
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

    // ---------------------------------------------------------------- lanes and window

    public function test_every_source_gets_a_lane_in_priority_order(): void
    {
        $this->actingAs($this->admin)
            ->get(route('manage.shows.planner'))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Manage/Shows/Planner')
                ->where('lanes', fn ($lanes) => collect($lanes)->pluck('name')->all() === [
                    'Main Stage',
                    'Stage B',
                ])
                ->where('can.edit', true)
            );
    }

    public function test_a_lane_carries_only_its_own_shows(): void
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
                $lanes = collect($page->toArray()['props']['lanes'])->keyBy('name');

                $this->assertSame(['On main'], collect($lanes['Main Stage']['shows'])->pluck('title')->all());
                $this->assertSame(['On B'], collect($lanes['Stage B']['shows'])->pluck('title')->all());
            });
    }

    public function test_the_window_defaults_to_four_days_from_today(): void
    {
        $this->actingAs($this->admin)
            ->get(route('manage.shows.planner'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('range.days', 4)
                ->count('range.dayLabels', 4)
                ->where('range.dayLabels.0.isToday', true)
            );
    }

    public function test_the_window_can_be_moved_and_widened(): void
    {
        $this->actingAs($this->admin)
            ->get(route('manage.shows.planner', ['from' => '2026-08-01', 'days' => 7]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('range.days', 7)
                ->count('range.dayLabels', 7)
                ->where('range.dayLabels.0.label', 'Sat 1 Aug')
            );
    }

    public function test_an_absurd_window_is_clamped_rather_than_served(): void
    {
        $this->actingAs($this->admin)
            ->get(route('manage.shows.planner', ['days' => 3650]))
            ->assertInertia(fn (Assert $page) => $page->where('range.days', 31));
    }

    public function test_a_broken_from_parameter_falls_back_to_today(): void
    {
        $this->actingAs($this->admin)
            ->get(route('manage.shows.planner', ['from' => 'last thursday-ish']))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page->where('range.dayLabels.0.isToday', true));
    }

    public function test_a_show_running_past_the_window_edge_still_appears(): void
    {
        // The dance case: starts inside the window, ends after it. Containment would drop it.
        $this->show([
            'title' => 'Overnight dance',
            'scheduled_start' => now()->addDays(3)->setTime(22, 0),
            'scheduled_end' => now()->addDays(5)->setTime(2, 0),
        ]);

        $this->actingAs($this->admin)
            ->get(route('manage.shows.planner', ['days' => 4]))
            ->assertInertia(fn (Assert $page) => $page->where(
                'lanes.0.shows',
                fn ($shows) => collect($shows)->pluck('title')->all() === ['Overnight dance'],
            ));
    }

    public function test_shows_outside_the_window_are_left_out(): void
    {
        $this->show(['title' => 'Next month', 'scheduled_start' => now()->addDays(40), 'scheduled_end' => now()->addDays(40)->addHour()]);

        $this->actingAs($this->admin)
            ->get(route('manage.shows.planner'))
            ->assertInertia(fn (Assert $page) => $page->count('lanes.0.shows', 0));
    }

    public function test_a_live_block_is_locked(): void
    {
        $this->show(['title' => 'On air', 'status' => 'live', 'scheduled_start' => now(), 'scheduled_end' => now()->addHour()]);

        $this->actingAs($this->admin)
            ->get(route('manage.shows.planner'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('lanes.0.shows.0.locked', true)
                ->where('lanes.0.shows.0.status.label', 'Live')
            );
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
            'recordable' => true,
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
        $this->assertTrue($show->recordable);
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
