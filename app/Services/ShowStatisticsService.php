<?php

namespace App\Services;

use App\Models\Server;
use App\Models\Show;
use App\Models\Source;
use App\Models\ShowStatistic;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ShowStatisticsService
{
    public function recordStatistics(Show $show): void
    {
        if (!$show->actual_start || $show->status !== 'live') {
            return;
        }

        // Get the source for this show
        $source = $show->source;
        
        $currentViewerCount = 0;
        $uniqueViewers = 0;
        
        if ($source) {
            // Get active viewer count from source_users table
            $currentViewerCount = $source->activeViewers()->count();
            
            // For unique viewers, count distinct users who have watched this source today
            $uniqueViewers = DB::table('source_users')
                ->where('source_id', $source->id)
                ->where('joined_at', '>=', now()->startOfDay())
                ->distinct('user_id')
                ->count('user_id');
                
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

    private function getHourlyStats(Show $show, Carbon $startTime, Carbon $endTime): Collection
    {
        return ShowStatistic::where('show_id', $show->id)
            ->whereBetween('recorded_at', [$startTime, $endTime])
            ->selectRaw('DATE_FORMAT(recorded_at, "%Y-%m-%d %H:00:00") as hour')
            ->selectRaw('AVG(viewer_count) as avg_viewers')
            ->selectRaw('MAX(viewer_count) as peak_viewers')
            ->selectRaw('MAX(unique_viewers) as unique_viewers')
            ->groupBy('hour')
            ->orderBy('hour')
            ->get();
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
            'trend' => $last5Minutes->map(fn($stat) => [
                'time' => $stat->recorded_at->format('H:i:s'),
                'count' => $stat->viewer_count,
            ]),
        ];
    }
}