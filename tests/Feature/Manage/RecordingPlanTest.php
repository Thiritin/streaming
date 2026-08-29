<?php

namespace Tests\Feature\Manage;

use App\Models\Event;
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

    private function event(string $name, $startsOn, $endsOn): Event
    {
        return Event::create([
            'name' => $name,
            'starts_on' => $startsOn->format('Y-m-d'),
            'ends_on' => $endsOn->format('Y-m-d'),
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
            ->get(route('manage.recordings.plan', ['show_skipped' => 1]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('rows.0.state', 'skipped')
                ->where('rows.0.gap', false));
    }

    /**
     * `no` is a decision, not a state of ignorance. Once it is made there is nothing left
     * to do about the row, so it comes off the page rather than sitting in it - which is
     * also what makes marking a run of unrecorded shows `no` in one bulk action actually
     * clear them out of the way.
     */
    public function test_a_show_nobody_is_publishing_is_off_the_page(): void
    {
        $kept = $this->show(['title' => 'Still to decide', 'publish_plan' => 'undecided']);
        $skipped = $this->show(['title' => 'Not publishing this', 'publish_plan' => 'no']);

        $this->actingAs($this->admin)
            ->get(route('manage.recordings.plan'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('rows', 1)
                ->where('rows.0.id', $kept->id));

        // Somebody has to be able to undo a `no` set by mistake, so it is a switch rather
        // than a one-way door.
        $this->actingAs($this->admin)
            ->get(route('manage.recordings.plan', ['show_skipped' => 1]))
            ->assertInertia(function (Assert $page) use ($kept, $skipped) {
                $ids = collect($page->toArray()['props']['rows'])->pluck('id');

                $this->assertEqualsCanonicalizing([$kept->id, $skipped->id], $ids->all());
            });
    }

    public function test_marking_a_show_skip_takes_it_out_of_every_count(): void
    {
        $this->show(['publish_plan' => 'yes', 'status' => 'ended']);
        $this->show([
            'publish_plan' => 'no',
            'status' => 'ended',
            'stream_condition' => 'lost',
            'onsite_condition' => 'lost',
        ]);

        $this->actingAs($this->admin)
            ->get(route('manage.recordings.plan'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary', function ($summary) {
                    $tiles = collect($summary)->keyBy('key')->map(fn ($tile) => $tile['value']);

                    // Not in the total, and its two lost captures are not in Lost either.
                    return $tiles['total'] === 1
                        && $tiles['to_publish'] === 1
                        && $tiles['lost'] === 0;
                }));
    }

    public function test_the_day_list_leaves_out_a_day_holding_only_skipped_shows(): void
    {
        $this->show(['publish_plan' => 'yes', 'scheduled_start' => '2026-08-01 10:00:00']);
        $this->show(['publish_plan' => 'no', 'scheduled_start' => '2026-08-02 10:00:00']);

        $this->actingAs($this->admin)
            ->get(route('manage.recordings.plan'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('options.days', fn ($days) => collect($days)->pluck('value')->all() === ['2026-08-01']));
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
        // Meant to go out, has aired, nothing published: the tile the page exists for.
        $this->show(['publish_plan' => 'yes', 'status' => 'ended']);
        // Not aired yet, so nothing is owed.
        $this->show(['publish_plan' => 'yes', 'status' => 'scheduled', 'recording_owner_id' => $this->admin->id]);
        $this->show(['publish_plan' => 'undecided']);
        // Off the page entirely, so it is not in the total either.
        $this->show(['publish_plan' => 'no']);

        $this->actingAs($this->admin)
            ->get(route('manage.recordings.plan'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary', function ($summary) {
                    $tiles = collect($summary)->keyBy('key')->map(fn ($tile) => $tile['value']);

                    return $tiles['total'] === 3
                        && $tiles['to_publish'] === 1
                        && $tiles['unassigned'] === 1
                        && $tiles['lost'] === 0
                        && $tiles['published'] === 0;
                }));
    }

    public function test_the_to_publish_filter_is_what_is_still_owed(): void
    {
        $missing = $this->show(['title' => 'Missing one', 'publish_plan' => 'yes', 'status' => 'ended']);
        $draft = $this->show(['title' => 'Cut but not out', 'publish_plan' => 'yes', 'status' => 'ended']);
        $this->recordingFor($draft);

        $out = $this->show(['title' => 'Already out', 'publish_plan' => 'yes', 'status' => 'ended']);
        $this->recordingFor($out, ['is_published' => true]);

        $this->show(['title' => 'Still to come', 'publish_plan' => 'yes', 'status' => 'scheduled']);
        $this->show(['title' => 'Nobody is publishing this', 'publish_plan' => 'no', 'status' => 'ended']);

        $this->actingAs($this->admin)
            ->get(route('manage.recordings.plan', ['state' => 'to_publish']))
            ->assertInertia(function (Assert $page) use ($missing, $draft) {
                $ids = collect($page->toArray()['props']['rows'])->pluck('id');

                // A cut that exists but has not gone out is still owed, which is why this
                // is broader than the red tint on a row with nothing at all.
                $this->assertEqualsCanonicalizing([$missing->id, $draft->id], $ids->all());
            });
    }

    /**
     * `no` is the only opt-out, and a row marked `no` is off the page altogether - so
     * anything still on the page is still on the table. A show whose material came back
     * perfectly well must not sit outside the outstanding list purely because nobody has
     * got round to ticking a box.
     */
    public function test_an_undecided_show_that_has_aired_is_still_owed(): void
    {
        $undecided = $this->show([
            'title' => 'Nobody has said either way',
            'publish_plan' => 'undecided',
            'status' => 'ended',
        ]);

        $this->actingAs($this->admin)
            ->get(route('manage.recordings.plan', ['state' => 'to_publish']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('rows', 1)
                ->where('rows.0.id', $undecided->id)
                // The red tint is narrower than the list: it wants an explicit yes, or a
                // running convention is all red by the second afternoon.
                ->where('rows.0.gap', false));
    }

    /**
     * The whole point of keeping the onsite detail: everything short of both captures
     * being gone is still something somebody owes the audience.
     */
    public function test_every_capture_short_of_both_gone_is_still_owed(): void
    {
        $cases = [
            'stream ok' => ['stream_condition' => 'ok', 'onsite_condition' => null],
            'nothing checked' => ['stream_condition' => null, 'onsite_condition' => null],
            'from onsite' => ['stream_condition' => 'lost', 'onsite_condition' => 'ok'],
            'onsite without audio' => ['stream_condition' => 'lost', 'onsite_condition' => 'no_audio'],
            'onsite without video' => ['stream_condition' => 'lost', 'onsite_condition' => 'no_video'],
            'onsite half there' => ['stream_condition' => 'lost', 'onsite_condition' => 'incomplete'],
            'stream gone, room not looked at' => ['stream_condition' => 'lost', 'onsite_condition' => null],
        ];

        $expected = [];

        foreach ($cases as $title => $captures) {
            $expected[] = $this->show($captures + [
                'title' => $title,
                'status' => 'ended',
                'publish_plan' => 'undecided',
            ])->id;
        }

        // The one that is not: both captures gone.
        $this->show([
            'title' => 'Gone for good',
            'status' => 'ended',
            'publish_plan' => 'undecided',
            'stream_condition' => 'lost',
            'onsite_condition' => 'lost',
        ]);

        $this->actingAs($this->admin)
            ->get(route('manage.recordings.plan', ['state' => 'to_publish']))
            ->assertInertia(function (Assert $page) use ($expected) {
                $ids = collect($page->toArray()['props']['rows'])->pluck('id');

                $this->assertEqualsCanonicalizing($expected, $ids->all());
            });
    }

    public function test_a_row_whose_material_is_gone_is_not_still_owed(): void
    {
        $this->show([
            'publish_plan' => 'yes',
            'status' => 'ended',
            'stream_condition' => 'lost',
            'onsite_condition' => 'lost',
        ]);

        $this->actingAs($this->admin)
            ->get(route('manage.recordings.plan', ['state' => 'to_publish']))
            ->assertInertia(fn (Assert $page) => $page->has('rows', 0));
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
        $this->show([
            'status' => 'ended',
            'publish_plan' => 'yes',
            'stream_condition' => 'ok',
        ]);

        $this->actingAs($this->admin)
            ->get(route('manage.recordings.plan'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('rows.0.needs_onsite', false)
                ->where('rows.0.state', 'missing'));
    }

    public function test_a_lost_stream_capture_asks_for_the_copy_from_the_room(): void
    {
        $this->show([
            'status' => 'ended',
            'publish_plan' => 'yes',
            'stream_condition' => 'lost',
        ]);

        $this->actingAs($this->admin)
            ->get(route('manage.recordings.plan'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('rows.0.needs_onsite', true)
                ->where('rows.0.lost', false)
                // Nothing is written off while the room's copy has not been looked at.
                ->where('rows.0.state', 'missing'));
    }

    public function test_a_usable_onsite_copy_is_what_the_cut_comes_from(): void
    {
        $this->show([
            'status' => 'ended',
            'publish_plan' => 'yes',
            'stream_condition' => 'lost',
            'onsite_condition' => 'ok',
        ]);

        $this->actingAs($this->admin)
            ->get(route('manage.recordings.plan'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('rows.0.state', 'onsite')
                ->where('rows.0.lost', false));
    }

    /**
     * The reason the onsite column keeps the detail the stream column dropped: missing
     * audio comes off the desk afterwards and a missing part is announced, so neither is
     * a reason to give up on the show.
     */
    public function test_a_damaged_onsite_copy_is_still_something_to_publish(): void
    {
        foreach (['no_audio', 'no_video', 'incomplete'] as $condition) {
            $show = $this->show([
                'status' => 'ended',
                'publish_plan' => 'yes',
                'stream_condition' => 'lost',
                'onsite_condition' => $condition,
            ]);

            $this->assertFalse($show->isLost(), $condition.' should not be a write-off');
            $this->assertSame('onsite', $show->recordingState(), $condition.' should still be cuttable');
            $this->assertTrue($show->isAwaitingPublication(), $condition.' is still owed');
        }
    }

    public function test_nothing_is_lost_until_both_captures_are_gone(): void
    {
        $streamOnly = $this->show([
            'status' => 'ended',
            'publish_plan' => 'yes',
            'stream_condition' => 'lost',
            'onsite_condition' => null,
        ]);

        $this->assertFalse($streamOnly->isLost());

        $both = $this->show([
            'status' => 'ended',
            'publish_plan' => 'yes',
            'stream_condition' => 'lost',
            'onsite_condition' => 'lost',
        ]);

        $this->assertTrue($both->isLost());
        $this->assertSame('lost', $both->recordingState());
    }

    public function test_a_show_nobody_is_publishing_is_never_lost(): void
    {
        $show = $this->show([
            'status' => 'ended',
            'publish_plan' => 'no',
            'stream_condition' => 'lost',
            'onsite_condition' => 'lost',
        ]);

        $this->assertSame('skipped', $show->recordingState());
        $this->assertFalse($show->isAwaitingPublication());
    }

    public function test_a_cut_that_exists_outranks_the_capture_notes(): void
    {
        $show = $this->show([
            'status' => 'ended',
            'publish_plan' => 'yes',
            'stream_condition' => 'lost',
            'onsite_condition' => 'lost',
        ]);

        $this->recordingFor($show, ['is_published' => true]);

        $this->assertSame('published', $show->fresh()->recordingState());
    }

    public function test_a_stream_condition_the_column_no_longer_carries_is_refused(): void
    {
        $show = $this->show(['stream_condition' => 'ok']);

        // The stream column is down to two answers; the detail lives on the onsite one.
        $this->actingAs($this->admin)
            ->patch(route('manage.shows.recording-plan', $show), ['stream_condition' => 'no_audio'])
            ->assertSessionHasErrors('stream_condition');

        $this->assertSame('ok', $show->fresh()->stream_condition);
    }

    public function test_an_unknown_onsite_condition_is_refused(): void
    {
        $show = $this->show(['onsite_condition' => 'ok']);

        $this->actingAs($this->admin)
            ->patch(route('manage.shows.recording-plan', $show), ['onsite_condition' => 'maybe'])
            ->assertSessionHasErrors('onsite_condition');

        $this->assertSame('ok', $show->fresh()->onsite_condition);
    }

    public function test_a_capture_verdict_can_be_cleared(): void
    {
        $show = $this->show(['stream_condition' => 'lost', 'onsite_condition' => 'lost']);

        $this->actingAs($this->admin)
            ->patch(route('manage.shows.recording-plan', $show), ['stream_condition' => null]);

        $this->actingAs($this->admin)
            ->patch(route('manage.shows.recording-plan', $show), ['onsite_condition' => null]);

        $show->refresh();

        $this->assertNull($show->stream_condition);
        $this->assertNull($show->onsite_condition);
    }

    public function test_bulk_writes_off_a_whole_selection(): void
    {
        $shows = collect([$this->show(['status' => 'ended']), $this->show(['status' => 'ended'])]);

        $this->actingAs($this->admin)
            ->post(route('manage.shows.recording-plan.bulk'), [
                'ids' => $shows->pluck('id')->all(),
                'stream_condition' => 'lost',
                'onsite_condition' => 'lost',
            ]);

        $shows->each(fn (Show $show) => $this->assertTrue($show->fresh()->isLost()));
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

    public function test_a_tag_is_whatever_somebody_typed(): void
    {
        $show = $this->show();

        $this->actingAs($this->admin)
            ->patch(route('manage.shows.recording-plan', $show), [
                'recording_tags' => ['Saved to NAS', 'handed to editor'],
            ]);

        // Folded to lower case on the way in, so "NAS" and "nas" are one errand rather
        // than two entries in the suggestion list.
        $this->assertSame(['saved to nas', 'handed to editor'], $show->fresh()->recordingTags());
    }

    public function test_a_tag_list_is_de_duplicated_and_capped(): void
    {
        $show = $this->show();

        $this->actingAs($this->admin)
            ->patch(route('manage.shows.recording-plan', $show), [
                'recording_tags' => ['  nas ', 'NAS', ''],
            ]);

        $this->assertSame(['nas'], $show->fresh()->recordingTags());
    }

    public function test_more_tags_than_a_row_may_carry_are_refused(): void
    {
        $show = $this->show();

        $this->actingAs($this->admin)
            ->patch(route('manage.shows.recording-plan', $show), [
                'recording_tags' => array_map(fn (int $index) => 'tag '.$index, range(1, Show::MAX_TAGS + 1)),
            ])
            ->assertSessionHasErrors('recording_tags');
    }

    public function test_the_tags_in_use_are_offered_back_as_the_vocabulary(): void
    {
        $this->show(['recording_tags' => ['saved to nas']]);
        $this->show(['recording_tags' => ['saved to nas', 'colour pass']]);

        $this->actingAs($this->admin)
            ->get(route('manage.recordings.plan'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('options.tags', ['colour pass', 'saved to nas']));
    }

    public function test_the_tag_filter_narrows_to_the_rows_carrying_it(): void
    {
        $tagged = $this->show(['title' => 'On the NAS', 'recording_tags' => ['saved to nas']]);
        $this->show(['title' => 'Not yet', 'recording_tags' => ['colour pass']]);
        $this->show(['title' => 'No tags at all']);

        $this->actingAs($this->admin)
            ->get(route('manage.recordings.plan', ['tag' => 'saved to nas']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('rows', 1)
                ->where('rows.0.id', $tagged->id));
    }

    /**
     * A selection spans rows carrying different tags, so one Apply adds to each list
     * rather than replacing every one of them with the same list.
     */
    public function test_bulk_adds_a_tag_without_flattening_what_is_already_there(): void
    {
        $one = $this->show(['recording_tags' => ['colour pass']]);
        $two = $this->show();

        $this->actingAs($this->admin)
            ->post(route('manage.shows.recording-plan.bulk'), [
                'ids' => [$one->id, $two->id],
                'add_tag' => 'Saved to NAS',
            ]);

        $this->assertSame(['colour pass', 'saved to nas'], $one->fresh()->recordingTags());
        $this->assertSame(['saved to nas'], $two->fresh()->recordingTags());
    }

    public function test_bulk_removes_a_tag_from_a_selection(): void
    {
        $show = $this->show(['recording_tags' => ['saved to nas', 'colour pass']]);

        $this->actingAs($this->admin)
            ->post(route('manage.shows.recording-plan.bulk'), [
                'ids' => [$show->id],
                'remove_tag' => 'saved to nas',
            ]);

        $this->assertSame(['colour pass'], $show->fresh()->recordingTags());
    }

    public function test_the_plan_opens_on_the_latest_event(): void
    {
        $current = $this->event('This Run', now()->subDay(), now()->addWeeks(3));
        $this->event('Last Run', now()->subYear(), now()->subYear()->addDays(4));

        $onNow = $this->show(['title' => 'On now']);
        $this->show([
            'title' => 'Last run',
            'scheduled_start' => now()->subYear(),
            'scheduled_end' => now()->subYear()->addHour(),
        ]);

        $this->actingAs($this->admin)
            ->get(route('manage.recordings.plan'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('rows', 1)
                ->where('rows.0.id', $onNow->id)
                ->where('filters.event', (string) $current->id)
                ->where('defaults.event', (string) $current->id));
    }

    public function test_the_plan_opens_on_the_run_that_just_finished(): void
    {
        $finished = $this->event('Last Run', now()->subMonth(), now()->subMonth()->addDays(4));
        // A run already in the calendar has no programme yet, so it is the wrong thing
        // to open on even though it is the newest by date.
        $this->event('Next Run', now()->addMonths(6), now()->addMonths(6)->addDays(4));

        $this->actingAs($this->admin)
            ->get(route('manage.recordings.plan'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.event', (string) $finished->id)
                ->where('defaults.event', (string) $finished->id));
    }

    public function test_an_earlier_event_can_be_asked_for(): void
    {
        $this->event('This Run', now()->subDay(), now()->addWeeks(3));
        $last = $this->event('Last Run', now()->subYear(), now()->subYear()->addDays(4));

        $earlier = $this->show([
            'title' => 'Last run',
            'scheduled_start' => now()->subYear(),
            'scheduled_end' => now()->subYear()->addHour(),
        ]);
        $this->show(['title' => 'On now']);

        $this->actingAs($this->admin)
            ->get(route('manage.recordings.plan', ['event' => $last->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->has('rows', 1)
                ->where('rows.0.id', $earlier->id));
    }

    public function test_all_events_switches_the_default_off(): void
    {
        $this->event('This Run', now()->subDay(), now()->addWeeks(3));
        $this->event('Last Run', now()->subYear(), now()->subYear()->addDays(4));

        $this->show([
            'scheduled_start' => now()->subYear(),
            'scheduled_end' => now()->subYear()->addHour(),
        ]);
        $this->show();

        $this->actingAs($this->admin)
            ->get(route('manage.recordings.plan', ['event' => 'all']))
            ->assertInertia(fn (Assert $page) => $page->has('rows', 2));
    }

    public function test_shows_filed_under_no_event_can_be_asked_for(): void
    {
        $this->event('This Run', now()->subDay(), now()->addWeeks(3));

        $this->show(['title' => 'On now']);
        $unfiled = $this->show([
            'title' => 'Filed nowhere',
            'scheduled_start' => now()->subYears(3),
            'scheduled_end' => now()->subYears(3)->addHour(),
        ]);

        $this->actingAs($this->admin)
            ->get(route('manage.recordings.plan', ['event' => 'none']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('rows', 1)
                ->where('rows.0.id', $unfiled->id));
    }

    public function test_a_chosen_day_outranks_the_event_default(): void
    {
        $this->event('This Run', now()->subDay(), now()->addWeeks(3));
        $this->event('Last Run', now()->subYear(), now()->subYear()->addDays(4));

        $last = $this->show([
            'title' => 'Last run',
            'scheduled_start' => now()->subYear(),
            'scheduled_end' => now()->subYear()->addHour(),
        ]);

        // A link to a day in a past run must not come back empty against a default the
        // sender never saw.
        $this->actingAs($this->admin)
            ->get(route('manage.recordings.plan', ['day' => $last->scheduled_start->format('Y-m-d')]))
            ->assertInertia(fn (Assert $page) => $page->has('rows', 1)->where('rows.0.id', $last->id));
    }

    public function test_a_nonsense_event_falls_back_to_the_default(): void
    {
        $current = $this->event('This Run', now()->subDay(), now()->addWeeks(3));
        $this->show();

        $this->actingAs($this->admin)
            ->get(route('manage.recordings.plan', ['event' => 'not-an-event']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.event', (string) $current->id)
                ->has('rows', 1));
    }

    public function test_an_installation_with_no_calendar_sees_everything(): void
    {
        $this->show([
            'scheduled_start' => now()->subYear(),
            'scheduled_end' => now()->subYear()->addHour(),
        ]);
        $this->show();

        $this->actingAs($this->admin)
            ->get(route('manage.recordings.plan'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.event', 'all')
                ->has('rows', 2));
    }

    public function test_the_event_list_offers_every_run_plus_all_and_none(): void
    {
        $this->event('This Run', now()->subDay(), now()->addWeeks(3));
        $this->event('Last Run', now()->subYear(), now()->subYear()->addDays(4));

        $this->actingAs($this->admin)
            ->get(route('manage.recordings.plan'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('options.events', function ($events) {
                    $values = collect($events)->pluck('value');

                    // Newest run first, behind the way to switch the filter off, and the
                    // pile of shows filed nowhere at the end.
                    return $values->first() === 'all'
                        && $values->last() === 'none'
                        && $values->count() === 4;
                }));
    }

    public function test_the_day_list_is_scoped_to_the_event_on_screen(): void
    {
        $this->event('This Run', now()->subDay(), now()->addWeeks(3));
        $this->event('Last Run', now()->subYear(), now()->subYear()->addDays(4));

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
