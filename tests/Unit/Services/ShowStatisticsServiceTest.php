<?php

namespace Tests\Unit\Services;

use App\Models\Show;
use App\Models\ShowStatistic;
use App\Models\Source;
use App\Models\SourceUser;
use App\Models\User;
use App\Services\ShowStatisticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * The sample a live show's statistics are built out of.
 *
 * Two numbers, taken a minute apart by RecordShowStatistics: how many are watching
 * now, and how many different viewers the show has had since it went on air. The
 * second one is a window with an edge, so it is pinned to that edge here rather than
 * trusted to whatever hour the suite happens to run at.
 */
class ShowStatisticsServiceTest extends TestCase
{
    use RefreshDatabase;

    private ShowStatisticsService $service;

    private Source $source;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new ShowStatisticsService;
        $this->source = Source::factory()->create();
    }

    private function liveShow($actualStart = null): Show
    {
        return Show::factory()->create([
            'source_id' => $this->source->id,
            'status' => 'live',
            'actual_start' => $actualStart ?? now()->subHour(),
        ]);
    }

    /**
     * A viewing session on this show's source, joined at the given moment and still
     * open, so it also counts as watching now.
     */
    private function viewing($joinedAt, ?User $user = null, ?Source $source = null): SourceUser
    {
        return SourceUser::create([
            'source_id' => ($source ?? $this->source)->id,
            'user_id' => $user?->id ?? User::factory()->create()->id,
            'joined_at' => $joinedAt,
            'left_at' => null,
            'last_heartbeat_at' => now(),
        ]);
    }

    /**
     * A signed-out viewer's session, identified by the key that follows them across
     * requests rather than by an account.
     */
    private function guestViewing(string $key, $joinedAt): SourceUser
    {
        return SourceUser::create([
            'source_id' => $this->source->id,
            'user_id' => null,
            'guest_key' => $key,
            'joined_at' => $joinedAt,
            'left_at' => null,
            'last_heartbeat_at' => now(),
        ]);
    }

    /**
     * The window is the show, from the instant it went on air, inclusive.
     *
     * Somebody who tuned in a second before it started was watching whatever the
     * source was carrying then, which is not this show.
     */
    public function test_unique_viewers_count_from_the_moment_the_show_started(): void
    {
        $start = today()->addHours(9);
        $show = $this->liveShow($start);

        $this->viewing($start->copy()->subSecond());   // the show before this one
        $this->viewing($start);                        // the boundary itself
        $this->viewing($start->copy()->addMinutes(20));

        $this->service->recordStatistics($show);

        $this->assertSame(2, ShowStatistic::where('show_id', $show->id)->value('unique_viewers'));
    }

    /**
     * The window used to be the calendar day on the source, which meant the morning's
     * audience was still being counted into the evening slot.
     */
    public function test_an_earlier_show_on_the_same_source_is_not_counted_in_this_one(): void
    {
        $show = $this->liveShow(today()->addHours(20));

        $this->viewing(today()->addHours(10));  // the morning slot
        $this->viewing(today()->addHours(21));

        $this->service->recordStatistics($show);

        $this->assertSame(1, ShowStatistic::where('show_id', $show->id)->value('unique_viewers'));
    }

    /**
     * A show that runs past midnight keeps its audience. Counting from the start of
     * the day reset this to nothing at 00:00, and the report takes the maximum across
     * the samples, so everyone who arrived for the second half was thrown away rather
     * than added.
     */
    public function test_a_show_crossing_midnight_keeps_counting(): void
    {
        $start = today()->addHours(22);
        $show = $this->liveShow($start);

        $this->viewing($start->copy()->addMinutes(30));   // before midnight
        $this->viewing($start->copy()->addHours(3));      // after it

        $this->travelTo($start->copy()->addHours(4));

        $this->service->recordStatistics($show);

        $this->assertSame(2, ShowStatistic::where('show_id', $show->id)->value('unique_viewers'));
    }

    public function test_unique_viewers_are_counted_per_source(): void
    {
        $show = $this->liveShow();
        $elsewhere = Source::factory()->create();

        $this->viewing(now()->subMinutes(10));
        $this->viewing(now()->subMinutes(10), source: $elsewhere);

        $this->service->recordStatistics($show);

        $this->assertSame(1, ShowStatistic::where('show_id', $show->id)->value('unique_viewers'));
    }

    /**
     * Both populations on one row. A guest has no account to be distinct on, so they
     * are counted by the key that follows them across requests - guest access is a
     * sign-in mode here, and a figure that left them out was describing a different
     * audience than the one beside it.
     */
    public function test_a_guest_counts_once_and_so_does_an_account(): void
    {
        $show = $this->liveShow();

        $this->viewing(now()->subMinutes(10));
        $this->guestViewing('guest-1', now()->subMinutes(10));
        $this->guestViewing('guest-2', now()->subMinutes(5));

        $this->service->recordStatistics($show);

        $sample = ShowStatistic::where('show_id', $show->id)->first();

        $this->assertSame(3, $sample->viewer_count);
        $this->assertSame(3, $sample->unique_viewers);
    }

    public function test_one_guest_coming_back_is_one_unique_viewer(): void
    {
        $show = $this->liveShow();

        // Closed and reopened: `trackUserAccess` matches an open session, so a viewer
        // who drops and comes back gets a second row rather than a second joined_at.
        $this->guestViewing('guest-1', now()->subMinutes(30))
            ->forceFill(['left_at' => now()->subMinutes(20)])->save();
        $this->guestViewing('guest-1', now()->subMinutes(10));

        $this->service->recordStatistics($show);

        $this->assertSame(1, ShowStatistic::where('show_id', $show->id)->value('unique_viewers'));
    }

    /**
     * The same trap on the signed-in side: two rows, one person, and counting rows
     * rather than viewers would read the reconnect as an arrival.
     */
    public function test_one_account_reconnecting_is_one_unique_viewer(): void
    {
        $show = $this->liveShow();
        $user = User::factory()->create();

        $this->viewing(now()->subMinutes(30), $user)
            ->forceFill(['left_at' => now()->subMinutes(20)])->save();
        $this->viewing(now()->subMinutes(10), $user);

        $this->assertSame(2, SourceUser::where('user_id', $user->id)->count());

        $this->service->recordStatistics($show);

        $this->assertSame(1, ShowStatistic::where('show_id', $show->id)->value('unique_viewers'));
    }

    public function test_the_sample_carries_the_live_count_and_moves_the_peak(): void
    {
        $show = $this->liveShow();
        $show->update(['peak_viewer_count' => 1]);

        $this->viewing(now()->subMinutes(10));
        $this->viewing(now()->subMinutes(10));

        $this->service->recordStatistics($show);

        $this->assertSame(2, ShowStatistic::where('show_id', $show->id)->value('viewer_count'));
        $this->assertSame(2, $show->fresh()->viewer_count);
        $this->assertSame(2, $show->fresh()->peak_viewer_count);
    }

    /**
     * The edges report through the cache, and they can see viewers the table has not
     * caught up with. The higher of the two wins, and only for the live count - the
     * unique one has no cached half.
     */
    public function test_a_higher_cached_count_wins_over_the_table(): void
    {
        $show = $this->liveShow();

        $this->viewing(now()->subMinutes(10));

        Cache::put("stream_total_viewers:{$this->source->slug}", 50);

        $this->service->recordStatistics($show);

        $this->assertSame(50, ShowStatistic::where('show_id', $show->id)->value('viewer_count'));
        $this->assertSame(1, ShowStatistic::where('show_id', $show->id)->value('unique_viewers'));
    }

    public function test_a_show_that_is_not_live_is_not_sampled(): void
    {
        $scheduled = Show::factory()->create([
            'source_id' => $this->source->id,
            'status' => 'scheduled',
            'actual_start' => null,
        ]);

        $ended = Show::factory()->create([
            'source_id' => $this->source->id,
            'status' => 'ended',
            'actual_start' => now()->subHours(3),
        ]);

        $this->viewing(now()->subMinutes(10));

        $this->service->recordStatistics($scheduled);
        $this->service->recordStatistics($ended);

        $this->assertSame(0, ShowStatistic::count());
    }
}
