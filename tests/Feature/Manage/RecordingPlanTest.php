<?php

namespace Tests\Feature\Manage;

use App\Models\Recording;
use App\Models\Show;
use App\Models\Source;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\SessionKey;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesManageUsers;
use Tests\TestCase;

/**
 * The recording plan: who is cutting what, and what nobody has cut.
 *
 * The point of the page is the overview, so most of what is asserted here is that a row
 * reads the right state and that the counts describe the rows on screen.
 */
class RecordingPlanTest extends TestCase
{
    use CreatesManageUsers;
    use RefreshDatabase;

    protected Source $source;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createManageUsers();

        $this->source = Source::factory()->create(['name' => 'Main Stage']);
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

    private function recordingFor(Show $show, array $attributes = []): Recording
    {
        return Recording::create($attributes + [
            'show_id' => $show->id,
            'source_id' => $show->source_id,
            'title' => $show->title,
            'date' => $show->scheduled_start,
            'status' => 'ready',
            'is_published' => false,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function toast(): array
    {
        return session(SessionKey::FlashData->value)['toast'] ?? [];
    }

    public function test_the_page_is_behind_the_manage_gate(): void
    {
        $this->actingAs($this->viewer)
            ->get(route('manage.recordings.plan'))
            ->assertForbidden();
    }

    public function test_a_show_with_no_recording_that_has_aired_reads_as_missing(): void
    {
        $this->show([
            'title' => 'Opening Ceremony',
            'status' => 'ended',
            'publish_plan' => 'yes',
        ]);

        $this->actingAs($this->admin)
            ->get(route('manage.recordings.plan'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Manage/Recordings/Plan')
                ->where('rows.0.title', 'Opening Ceremony')
                ->where('rows.0.state', 'missing')
                ->where('rows.0.gap', true));
    }

    public function test_a_show_still_to_come_is_pending_rather_than_missing(): void
    {
        $this->show(['publish_plan' => 'yes', 'status' => 'scheduled']);

        $this->actingAs($this->admin)
            ->get(route('manage.recordings.plan'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('rows.0.state', 'pending')
                ->where('rows.0.gap', false));
    }

    public function test_a_show_marked_skip_is_not_a_gap(): void
    {
        $this->show(['publish_plan' => 'no', 'status' => 'ended']);

        $this->actingAs($this->admin)
            ->get(route('manage.recordings.plan'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('rows.0.state', 'skipped')
                ->where('rows.0.gap', false));
    }

    public function test_a_published_recording_wins_over_a_draft_one(): void
    {
        $show = $this->show(['status' => 'ended', 'publish_plan' => 'yes']);
        $this->recordingFor($show, ['status' => 'draft']);
        $this->recordingFor($show, ['status' => 'ready', 'is_published' => true, 'title' => 'Cut two']);

        $this->actingAs($this->admin)
            ->get(route('manage.recordings.plan'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('rows.0.state', 'published')
                ->where('rows.0.recording_count', 2));
    }

    public function test_the_summary_counts_the_rows_on_screen(): void
    {
        $this->show(['publish_plan' => 'yes', 'status' => 'ended']);
        $this->show(['publish_plan' => 'yes', 'status' => 'scheduled', 'recording_owner_id' => $this->admin->id]);
        $this->show(['publish_plan' => 'undecided']);
        $this->show(['publish_plan' => 'no']);

        $this->actingAs($this->admin)
            ->get(route('manage.recordings.plan'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary', function ($summary) {
                    $tiles = collect($summary)->keyBy('key')->map(fn ($tile) => $tile['value']);

                    return $tiles['total'] === 4
                        && $tiles['undecided'] === 1
                        // One of the two shows meant for publication has nobody on it.
                        && $tiles['unassigned'] === 1
                        && $tiles['gaps'] === 1
                        && $tiles['lost'] === 0;
                }));
    }

    public function test_the_gaps_filter_leaves_only_shows_that_produced_nothing(): void
    {
        $missing = $this->show(['title' => 'Missing one', 'publish_plan' => 'yes', 'status' => 'ended']);
        $cut = $this->show(['title' => 'Has a cut', 'publish_plan' => 'yes', 'status' => 'ended']);
        $this->recordingFor($cut);
        $this->show(['title' => 'Still to come', 'publish_plan' => 'yes', 'status' => 'scheduled']);

        $this->actingAs($this->admin)
            ->get(route('manage.recordings.plan', ['state' => 'gaps']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('rows', 1)
                ->where('rows.0.id', $missing->id));
    }

    public function test_a_cell_saves_on_its_own(): void
    {
        $show = $this->show(['publish_plan' => 'undecided']);

        $this->actingAs($this->admin)
            ->patch(route('manage.shows.recording-plan', $show), ['publish_plan' => 'yes'])
            ->assertRedirect();

        $this->assertSame('yes', $show->fresh()->publish_plan);
    }

    public function test_a_field_that_is_not_sent_is_left_alone(): void
    {
        $show = $this->show([
            'publish_plan' => 'yes',
            'recording_owner_id' => $this->admin->id,
            'recording_note' => 'Trim the intro',
        ]);

        $this->actingAs($this->admin)
            ->patch(route('manage.shows.recording-plan', $show), ['publish_plan' => 'no']);

        $show->refresh();

        $this->assertSame('no', $show->publish_plan);
        $this->assertSame($this->admin->id, $show->recording_owner_id);
        $this->assertSame('Trim the intro', $show->recording_note);
    }

    public function test_an_owner_can_be_cleared(): void
    {
        $show = $this->show(['recording_owner_id' => $this->admin->id]);

        $this->actingAs($this->admin)
            ->patch(route('manage.shows.recording-plan', $show), ['recording_owner_id' => null]);

        $this->assertNull($show->fresh()->recording_owner_id);
    }

    public function test_an_unknown_plan_is_refused(): void
    {
        $show = $this->show(['publish_plan' => 'yes']);

        $this->actingAs($this->admin)
            ->patch(route('manage.shows.recording-plan', $show), ['publish_plan' => 'maybe'])
            ->assertSessionHasErrors('publish_plan');

        $this->assertSame('yes', $show->fresh()->publish_plan);
    }

    public function test_bulk_applies_to_every_ticked_row(): void
    {
        $shows = collect([$this->show(), $this->show(), $this->show()]);

        $this->actingAs($this->admin)
            ->post(route('manage.shows.recording-plan.bulk'), [
                'ids' => $shows->pluck('id')->all(),
                'publish_plan' => 'yes',
                'recording_owner_id' => $this->admin->id,
            ]);

        $shows->each(function (Show $show) {
            $show->refresh();
            $this->assertSame('yes', $show->publish_plan);
            $this->assertSame($this->admin->id, $show->recording_owner_id);
        });

        $this->assertSame('3 shows updated', $this->toast()['title'] ?? null);
    }

    public function test_bulk_with_nothing_chosen_changes_nothing(): void
    {
        $show = $this->show(['publish_plan' => 'undecided']);

        $this->actingAs($this->admin)
            ->post(route('manage.shows.recording-plan.bulk'), ['ids' => [$show->id]]);

        $this->assertSame('undecided', $show->fresh()->publish_plan);
        $this->assertSame('Nothing to apply', $this->toast()['title'] ?? null);
    }

    public function test_a_moderator_can_read_the_plan_but_not_change_it(): void
    {
        $show = $this->show(['publish_plan' => 'undecided']);

        $this->actingAs($this->moderator)
            ->get(route('manage.recordings.plan'))
            ->assertInertia(fn (Assert $page) => $page->where('can_edit', false));

        $this->actingAs($this->moderator)
            ->patch(route('manage.shows.recording-plan', $show), ['publish_plan' => 'yes'])
            ->assertForbidden();

        $this->assertSame('undecided', $show->fresh()->publish_plan);
    }

    public function test_an_owner_who_no_longer_qualifies_stays_in_the_options(): void
    {
        // No roles at all, so nothing about them says they belong on this list except
        // that they are already holding a row.
        $stranger = User::factory()->create(['name' => 'Former Volunteer']);
        $this->show(['recording_owner_id' => $stranger->id]);

        $this->actingAs($this->admin)
            ->get(route('manage.recordings.plan'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('options.owners', fn ($owners) => collect($owners)
                    ->contains(fn ($owner) => $owner['label'] === 'Former Volunteer')));
    }

    public function test_a_clean_stream_capture_needs_no_onsite_copy(): void
    {
        $this->show(['publish_plan' => 'yes', 'status' => 'ended', 'stream_condition' => 'ok']);

        $this->actingAs($this->admin)
            ->get(route('manage.recordings.plan'))
            ->assertInertia(fn (Assert $page) => $page
                // The point of the redesign: nobody goes looking for a card they do not
                // need. The onsite column stays dark for this row.
                ->where('rows.0.needs_onsite', false));
    }

    public function test_a_failed_stream_capture_asks_for_the_onsite_copy(): void
    {
        $show = $this->show([
            'title' => 'Silent panel',
            'publish_plan' => 'yes',
            'status' => 'ended',
            'stream_condition' => 'no_audio',
        ]);

        $this->actingAs($this->admin)
            ->get(route('manage.recordings.plan'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('rows.0.needs_onsite', true)
                ->where('rows.0.state', 'needs_onsite')
                // Not a gap: it is accounted for and someone is chasing it.
                ->where('rows.0.gap', false));

        $this->actingAs($this->admin)
            ->get(route('manage.recordings.plan', ['state' => 'needs_onsite']))
            ->assertInertia(fn (Assert $page) => $page->has('rows', 1)->where('rows.0.id', $show->id));
    }

    public function test_a_received_onsite_master_is_waiting_on_an_import(): void
    {
        $this->show([
            'publish_plan' => 'yes',
            'status' => 'ended',
            'stream_condition' => 'lost',
            'onsite_status' => 'received',
        ]);

        $this->actingAs($this->admin)
            ->get(route('manage.recordings.plan'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('rows.0.state', 'onsite')
                ->where('rows.0.needs_onsite', false)
                ->where('rows.0.written_off', false));
    }

    public function test_nothing_is_written_off_until_both_captures_are_gone(): void
    {
        // Stream gone, but nobody has looked for the card yet: still a job, not a loss.
        $chasing = $this->show(['publish_plan' => 'yes', 'status' => 'ended', 'stream_condition' => 'lost']);

        $this->actingAs($this->admin)
            ->get(route('manage.recordings.plan'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('rows.0.state', 'needs_onsite')
                ->where('rows.0.written_off', false));

        $chasing->update(['onsite_status' => 'none']);

        $this->actingAs($this->admin)
            ->get(route('manage.recordings.plan'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('rows.0.state', 'lost')
                ->where('rows.0.written_off', true)
                ->where('rows.0.gap', false));
    }

    public function test_an_unusable_onsite_copy_writes_the_show_off_too(): void
    {
        $this->show([
            'publish_plan' => 'yes',
            'status' => 'ended',
            'stream_condition' => 'lost',
            'onsite_status' => 'unusable',
        ]);

        $this->actingAs($this->admin)
            ->get(route('manage.recordings.plan', ['state' => 'lost']))
            ->assertInertia(fn (Assert $page) => $page->has('rows', 1)->where('rows.0.state', 'lost'));
    }

    public function test_a_show_nobody_is_publishing_is_never_a_write_off(): void
    {
        $this->show([
            'publish_plan' => 'no',
            'status' => 'ended',
            'stream_condition' => 'lost',
            'onsite_status' => 'none',
        ]);

        $this->actingAs($this->admin)
            ->get(route('manage.recordings.plan'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('rows.0.state', 'skipped')
                ->where('rows.0.needs_onsite', false));
    }

    public function test_a_cut_that_exists_outranks_the_capture_notes(): void
    {
        $show = $this->show([
            'publish_plan' => 'yes',
            'status' => 'ended',
            'stream_condition' => 'lost',
            'onsite_status' => 'none',
        ]);
        $this->recordingFor($show, ['is_published' => true]);

        $this->actingAs($this->admin)
            ->get(route('manage.recordings.plan'))
            ->assertInertia(fn (Assert $page) => $page->where('rows.0.state', 'published'));
    }

    public function test_an_unknown_capture_value_is_refused(): void
    {
        $show = $this->show(['stream_condition' => 'ok']);

        $this->actingAs($this->admin)
            ->patch(route('manage.shows.recording-plan', $show), ['stream_condition' => 'meh'])
            ->assertSessionHasErrors('stream_condition');

        $this->actingAs($this->admin)
            ->patch(route('manage.shows.recording-plan', $show), ['onsite_status' => 'maybe'])
            ->assertSessionHasErrors('onsite_status');

        $this->assertSame('ok', $show->fresh()->stream_condition);
    }

    public function test_a_capture_verdict_can_be_cleared(): void
    {
        $show = $this->show(['stream_condition' => 'no_audio', 'onsite_status' => 'expected']);

        $this->actingAs($this->admin)
            ->patch(route('manage.shows.recording-plan', $show), ['stream_condition' => null]);
        $this->actingAs($this->admin)
            ->patch(route('manage.shows.recording-plan', $show), ['onsite_status' => null]);

        $show->refresh();

        $this->assertNull($show->stream_condition);
        $this->assertNull($show->onsite_status);
    }

    public function test_bulk_writes_off_a_whole_selection(): void
    {
        $shows = collect([
            $this->show(['publish_plan' => 'yes', 'status' => 'ended']),
            $this->show(['publish_plan' => 'yes', 'status' => 'ended']),
        ]);

        $this->actingAs($this->admin)
            ->post(route('manage.shows.recording-plan.bulk'), [
                'ids' => $shows->pluck('id')->all(),
                'stream_condition' => 'lost',
                'onsite_status' => 'none',
            ]);

        $shows->each(function (Show $show) {
            $this->assertTrue($show->fresh()->isWrittenOff());
        });
    }

    public function test_the_mine_filter_shows_only_your_own_rows(): void
    {
        $mine = $this->show(['title' => 'Mine', 'recording_owner_id' => $this->admin->id]);
        $this->show(['title' => 'Someone elses', 'recording_owner_id' => $this->moderator->id]);
        $this->show(['title' => 'Nobodys']);

        $this->actingAs($this->admin)
            ->get(route('manage.recordings.plan', ['mine' => 1]))
            ->assertInertia(fn (Assert $page) => $page
                ->has('rows', 1)
                ->where('rows.0.id', $mine->id));
    }

    public function test_the_page_says_who_you_are_so_a_row_can_be_claimed(): void
    {
        $this->show();

        $this->actingAs($this->admin)
            ->get(route('manage.recordings.plan'))
            ->assertInertia(fn (Assert $page) => $page->where('me.id', $this->admin->id));
    }

    public function test_grouping_by_person_puts_unassigned_rows_last(): void
    {
        $this->show(['title' => 'Nobodys', 'scheduled_start' => now()->subHour(), 'scheduled_end' => now()]);
        $this->show([
            'title' => 'Assigned',
            'recording_owner_id' => $this->admin->id,
            'scheduled_start' => now()->addHour(),
            'scheduled_end' => now()->addHours(2),
        ]);

        $this->actingAs($this->admin)
            ->get(route('manage.recordings.plan', ['group' => 'owner']))
            ->assertInertia(fn (Assert $page) => $page
                // Schedule order would have put the unassigned one first.
                ->where('rows.0.title', 'Assigned')
                ->where('rows.1.title', 'Nobodys'));
    }

    public function test_an_unknown_grouping_falls_back_to_the_day(): void
    {
        $this->show();

        $this->actingAs($this->admin)
            ->get(route('manage.recordings.plan', ['group' => 'nonsense']))
            ->assertInertia(fn (Assert $page) => $page->where('filters.group', 'day'));
    }

    public function test_ticking_the_archive_chips_stamps_when_they_went_up(): void
    {
        $show = $this->show(['status' => 'ended']);

        $this->assertTrue($show->needsMediaArchive());

        $this->actingAs($this->admin)
            ->patch(route('manage.shows.recording-plan', $show), ['archive_pgm' => true]);

        $show->refresh();

        $this->assertNotNull($show->archive_pgm_at);
        $this->assertNull($show->archive_iso_at);
        $this->assertTrue($show->isMediaArchived());
        $this->assertFalse($show->needsMediaArchive());
    }

    public function test_re_ticking_an_archive_chip_keeps_the_original_time(): void
    {
        $show = $this->show(['status' => 'ended', 'archive_pgm_at' => now()->subDay()]);
        $first = $show->archive_pgm_at;

        $this->actingAs($this->admin)
            ->patch(route('manage.shows.recording-plan', $show), ['archive_pgm' => true]);

        // When it first went up is the useful answer, not when someone last looked.
        $this->assertTrue($first->equalTo($show->fresh()->archive_pgm_at));
    }

    public function test_unticking_an_archive_chip_clears_the_time(): void
    {
        $show = $this->show(['status' => 'ended', 'archive_iso_at' => now()]);

        $this->actingAs($this->admin)
            ->patch(route('manage.shows.recording-plan', $show), ['archive_iso' => false]);

        $this->assertNull($show->fresh()->archive_iso_at);
    }

    public function test_the_archive_is_tracked_apart_from_publication(): void
    {
        // Nobody is publishing this one, but it still has to be deposited.
        $show = $this->show(['publish_plan' => 'no', 'status' => 'ended']);

        $this->assertTrue($show->needsMediaArchive());

        $this->actingAs($this->admin)
            ->get(route('manage.recordings.plan', ['state' => 'not_archived']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('rows', 1)
                ->where('rows.0.id', $show->id)
                ->where('rows.0.needs_archive', true)
                ->where('rows.0.archive_pgm', false));
    }

    public function test_nothing_is_expected_on_the_archive_for_a_write_off(): void
    {
        $show = $this->show([
            'publish_plan' => 'yes',
            'status' => 'ended',
            'stream_condition' => 'lost',
            'onsite_status' => 'none',
        ]);

        // There is no file to send, so it is not on anyone's upload list.
        $this->assertFalse($show->needsMediaArchive());

        $this->actingAs($this->admin)
            ->get(route('manage.recordings.plan', ['state' => 'not_archived']))
            ->assertInertia(fn (Assert $page) => $page->has('rows', 0));
    }

    public function test_a_show_that_has_not_happened_is_not_waiting_on_an_upload(): void
    {
        $show = $this->show(['status' => 'scheduled']);

        $this->assertFalse($show->needsMediaArchive());
    }

    public function test_bulk_can_mark_a_selection_as_uploaded(): void
    {
        $shows = collect([$this->show(['status' => 'ended']), $this->show(['status' => 'ended'])]);

        $this->actingAs($this->admin)
            ->post(route('manage.shows.recording-plan.bulk'), [
                'ids' => $shows->pluck('id')->all(),
                'archive_pgm' => true,
                'archive_iso' => true,
            ]);

        $shows->each(function (Show $show) {
            $show->refresh();
            $this->assertNotNull($show->archive_pgm_at);
            $this->assertNotNull($show->archive_iso_at);
        });
    }

    public function test_the_plan_opens_on_this_year_only(): void
    {
        $thisYear = $this->show([
            'title' => 'This year',
            'scheduled_start' => now()->startOfYear()->addMonth(),
            'scheduled_end' => now()->startOfYear()->addMonth()->addHour(),
        ]);
        $this->show([
            'title' => 'Last year',
            'scheduled_start' => now()->subYear(),
            'scheduled_end' => now()->subYear()->addHour(),
        ]);

        $this->actingAs($this->admin)
            ->get(route('manage.recordings.plan'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('rows', 1)
                ->where('rows.0.id', $thisYear->id)
                ->where('filters.year', (string) now()->year)
                ->where('defaults.year', (string) now()->year));
    }

    public function test_an_earlier_year_can_be_asked_for(): void
    {
        $last = $this->show([
            'title' => 'Last year',
            'scheduled_start' => now()->subYear(),
            'scheduled_end' => now()->subYear()->addHour(),
        ]);
        $this->show(['title' => 'This year']);

        $this->actingAs($this->admin)
            ->get(route('manage.recordings.plan', ['year' => now()->subYear()->year]))
            ->assertInertia(fn (Assert $page) => $page
                ->has('rows', 1)
                ->where('rows.0.id', $last->id));
    }

    public function test_all_years_switches_the_default_off(): void
    {
        $this->show([
            'scheduled_start' => now()->subYear(),
            'scheduled_end' => now()->subYear()->addHour(),
        ]);
        $this->show();

        $this->actingAs($this->admin)
            ->get(route('manage.recordings.plan', ['year' => 'all']))
            ->assertInertia(fn (Assert $page) => $page->has('rows', 2));
    }

    public function test_a_chosen_day_outranks_the_year_default(): void
    {
        $last = $this->show([
            'title' => 'Last year',
            'scheduled_start' => now()->subYear(),
            'scheduled_end' => now()->subYear()->addHour(),
        ]);

        // A link to a day in a past year must not come back empty against a default the
        // sender never saw.
        $this->actingAs($this->admin)
            ->get(route('manage.recordings.plan', ['day' => $last->scheduled_start->format('Y-m-d')]))
            ->assertInertia(fn (Assert $page) => $page->has('rows', 1)->where('rows.0.id', $last->id));
    }

    public function test_a_nonsense_year_falls_back_to_the_default(): void
    {
        $this->show();

        $this->actingAs($this->admin)
            ->get(route('manage.recordings.plan', ['year' => 'not-a-year']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.year', (string) now()->year)
                ->has('rows', 1));
    }

    public function test_the_year_list_offers_every_year_that_has_shows(): void
    {
        $this->show([
            'scheduled_start' => now()->subYears(2),
            'scheduled_end' => now()->subYears(2)->addHour(),
        ]);

        $this->actingAs($this->admin)
            ->get(route('manage.recordings.plan'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('options.years', function ($years) {
                    $values = collect($years)->pluck('value');

                    // Newest first, the current year always present even with nothing in
                    // it, and a way to switch the filter off at the end.
                    return $values->first() === (string) now()->year
                        && $values->contains((string) now()->subYears(2)->year)
                        && $values->last() === 'all';
                }));
    }

    public function test_the_day_list_is_scoped_to_the_year_on_screen(): void
    {
        $this->show([
            'scheduled_start' => now()->subYear(),
            'scheduled_end' => now()->subYear()->addHour(),
        ]);
        $this->show();

        $this->actingAs($this->admin)
            ->get(route('manage.recordings.plan'))
            ->assertInertia(fn (Assert $page) => $page->has('options.days', 1));
    }
}
