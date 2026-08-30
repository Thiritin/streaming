<?php

namespace App\Services;

use App\Models\Show;
use App\Models\ShowStatistic;
use App\Models\Source;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ShowStatisticsService
{
    public function recordStatistics(Show $show): void
    {
        if (! $show->actual_start || $show->status !== 'live') {
            return;
        }

        // Get the source for this show
        $source = $show->source;

        $currentViewerCount = 0;
        $uniqueViewers = 0;

        if ($source) {
            // Get active viewer count from source_users table
            $currentViewerCount = $source->activeViewers()->count();

            $uniqueViewers = $this->uniqueViewers($source, $show->actual_start);

            // Also check cache as fallback (in case edge servers are reporting)
            $cachedCount = Cache::get("stream_total_viewers:{$source->slug}", 0);

            // Use the higher of the two counts (in case edge servers are reporting higher numbers)
            if ($cachedCount > $currentViewerCount) {
                $currentViewerCount = $cachedCount;
            }
        }

        ShowStatistic::create([
            'show_id' => $show->id,
            'viewer_count' => $currentViewerCount,
            'unique_viewers' => $uniqueViewers,
            'recorded_at' => now(),
        ]);

        if ($currentViewerCount > $show->peak_viewer_count) {
            $show->update(['peak_viewer_count' => $currentViewerCount]);
        }

        $show->update(['viewer_count' => $currentViewerCount]);
    }

    /**
     * How many different viewers this show has had, counted from the moment it went
     * on air.
     *
     * Not "today, on this source", which is what it used to be. That window belonged
     * to the calendar rather than the show: a source running a morning slot and an
     * evening one folded the morning's audience into the evening's figure, and a show
     * crossing midnight started again from nothing - `getShowStatistics` reports the
     * maximum across the samples, so the small hours were discarded rather than added.
     *
     * The window cannot open onto the whole table: `recordStatistics` returns before
     * this for a show without an `actual_start`, which is the only way it could be
     * null.
     *
     * Counted per viewer and not per row. `HlsController::trackUserAccess` matches an
     * open session, so a viewer who drops and comes back gets a second `source_users`
     * row and counting rows would read a reconnect as another person. Two queries
     * rather than one over `coalesce(user_id, guest_key)`, which needs a cast on every
     * engine: a row carries one or the other, so the two counts add. A guest counts
     * once, by the key that identifies them across requests - they are watching either
     * way, and leaving them out made one row on the sample count two populations.
     */
    private function uniqueViewers(Source $source, Carbon $since): int
    {
        $sessions = DB::table('source_users')
            ->where('source_id', $source->id)
            ->where('joined_at', '>=', $since);

        return (clone $sessions)->whereNotNull('user_id')->distinct()->count('user_id')
            + (clone $sessions)->whereNull('user_id')->distinct()->count('guest_key');
    }

    public function getShowStatistics(Show $show): array
    {
        $startTime = $show->actual_start ?? $show->scheduled_start;
        $endTime = $show->actual_end ?? ($show->status === 'live' ? now() : $show->scheduled_end);

        $statistics = ShowStatistic::where('show_id', $show->id)
            ->whereBetween('recorded_at', [$startTime, $endTime])
            ->orderBy('recorded_at')
            ->get();

        // Get total unique viewers from statistics
        $totalUniqueViewers = $statistics->max('unique_viewers') ?? 0;

        $averageViewers = $statistics->avg('viewer_count') ?? 0;
        $peakViewers = $statistics->max('viewer_count') ?? 0;
        $minViewers = $statistics->min('viewer_count') ?? 0;

        $timeSeriesData = $statistics->map(function ($stat) {
            return [
                'time' => $stat->recorded_at->format('Y-m-d H:i:s'),
                'viewers' => $stat->viewer_count,
                'unique' => $stat->unique_viewers,
            ];
        });

        $hourlyStats = $this->getHourlyStats($show, $startTime, $endTime);

        // Calculate total view minutes based on average viewers and duration
        $durationMinutes = $startTime->diffInMinutes($endTime);
        $totalViewMinutes = round($averageViewers * $durationMinutes);

        return [
            'current_viewers' => $show->status === 'live' ? $show->viewer_count : 0,
            'peak_viewers' => $peakViewers,
            'average_viewers' => round($averageViewers, 0),
            'min_viewers' => $minViewers,
            'total_unique_viewers' => $totalUniqueViewers,
            'time_series' => $timeSeriesData,
            'hourly_stats' => $hourlyStats,
            'total_duration_minutes' => $durationMinutes,
            'total_view_minutes' => $totalViewMinutes,
        ];
    }

    /**
     * Average, peak and unique viewers per hour of the broadcast.
     *
     * Bucketed in PHP rather than with DATE_FORMAT: that function is MySQL-only, so this
     * query threw "column %Y-%m-%d %H:00:00 does not exist" on Postgres and SQLite, taking
     * the whole statistics page down everywhere except production.
     *
     * The row count is bounded by the length of a show at one sample per minute, so there
     * is nothing to gain from pushing the grouping into the database.
     */
    private function getHourlyStats(Show $show, Carbon $startTime, Carbon $endTime): Collection
    {
        return ShowStatistic::where('show_id', $show->id)
            ->whereBetween('recorded_at', [$startTime, $endTime])
            ->orderBy('recorded_at')
            ->get(['recorded_at', 'viewer_count', 'unique_viewers'])
            ->groupBy(fn (ShowStatistic $stat) => $stat->recorded_at->format('Y-m-d H:00:00'))
            ->map(fn (Collection $hour, string $key) => [
                'hour' => $key,
                'avg_viewers' => round($hour->avg('viewer_count'), 1),
                'peak_viewers' => (int) $hour->max('viewer_count'),
                'unique_viewers' => (int) $hour->max('unique_viewers'),
            ])
            ->values();
    }

    public function getRealtimeStats(Show $show): array
    {
        // Get current viewers from cache
        $source = Source::where('slug', $show->slug)
            ->orWhere('id', $show->source_id)
            ->first();

        $currentViewers = 0;
        if ($source) {
            $currentViewers = Cache::get("stream_total_viewers:{$source->slug}", 0);
        }

        $last5Minutes = ShowStatistic::where('show_id', $show->id)
            ->where('recorded_at', '>=', now()->subMinutes(5))
            ->orderBy('recorded_at', 'desc')
            ->limit(30)
            ->get()
            ->reverse()
            ->values();

        return [
            'current' => $currentViewers,
            'trend' => $last5Minutes->map(fn ($stat) => [
                'time' => $stat->recorded_at->format('H:i:s'),
                'count' => $stat->viewer_count,
            ]),
        ];
    }
}
