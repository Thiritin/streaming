<?php

namespace App\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Models\Show;
use App\Models\ShowStatistic;
use App\Support\Manage\Status;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Inertia\Response;

/**
 * Two views of the same show, because they answer different questions.
 *
 * Live  - what is happening right now, refreshed on a poll: current viewers, the shape of
 *         the last half hour, and whether people are arriving or leaving.
 * Report - what happened, once it is over: viewers across the whole broadcast, the totals
 *          worth quoting, and who watched.
 */
class ShowStatisticsController extends Controller
{
    /**
     * Chart points are capped so a long broadcast stays readable and the payload stays
     * small; samples are averaged into buckets rather than thinned, so a spike between two
     * kept samples is not silently dropped.
     */
    private const MAX_POINTS = 120;

    public function __invoke(Show $show): Response
    {
        $this->authorize('view', $show);

        $samples = ShowStatistic::query()
            ->where('show_id', $show->id)
            ->orderBy('recorded_at')
            ->get(['recorded_at', 'viewer_count', 'unique_viewers']);

        return inertia('Manage/Shows/Statistics', [
            'show' => [
                'id' => $show->id,
                'title' => $show->title,
                'source' => $show->source?->name,
                'status' => Status::show($show->status),
                'is_live' => $show->status === 'live',
                'scheduled_start' => $show->scheduled_start?->format('M j, Y H:i'),
                'scheduled_end' => $show->scheduled_end?->format('M j, Y H:i'),
                'actual_start' => $show->actual_start?->format('M j, Y H:i:s'),
                'actual_end' => $show->actual_end?->format('M j, Y H:i:s'),
                'formatted_duration' => $show->formatted_duration,
                'edit_url' => route('manage.shows.edit', $show),
            ],
            'live' => $show->status === 'live' ? $this->live($show, $samples) : null,
            'report' => $this->report($show, $samples),
            'viewers' => $this->viewers($show),
        ]);
    }

    /**
     * @param  Collection<int, ShowStatistic>  $samples
     * @return array<string, mixed>
     */
    private function live(Show $show, Collection $samples): array
    {
        $since = now()->subMinutes(30);
        $recent = $samples->filter(fn (ShowStatistic $sample) => $sample->recorded_at->gte($since));

        $sessions = $show->viewerSessions();

        return [
            'current' => (int) $show->viewer_count,
            'peak' => (int) $samples->max('viewer_count'),
            // Arrivals and departures over the last five minutes: the number that says
            // whether a dip is people leaving or the stream dropping.
            'joins' => (clone $sessions)->where('joined_at', '>=', now()->subMinutes(5))->count(),
            'leaves' => (clone $sessions)->where('left_at', '>=', now()->subMinutes(5))->count(),
            'watching' => (clone $sessions)->whereNull('left_at')->count(),
            'sparkline' => $this->points($recent, cap: 30),
        ];
    }

    /**
     * @param  Collection<int, ShowStatistic>  $samples
     * @return array<string, mixed>
     */
    private function report(Show $show, Collection $samples): array
    {
        $average = $samples->avg('viewer_count') ?? 0;
        $minutes = $samples->count();

        return [
            'peak' => (int) $samples->max('viewer_count'),
            'average' => (int) round($average),
            'unique' => (int) $samples->max('unique_viewers'),
            // Samples land once a minute, so summing them is the watch-minutes total
            // directly - no need to multiply an average by a duration and hope they agree.
            'watch_hours' => round($samples->sum('viewer_count') / 60, 1),
            'sampled_minutes' => $minutes,
            'chart' => $this->points($samples, cap: self::MAX_POINTS),
        ];
    }

    /**
     * Samples to chart points, averaged into at most `cap` buckets.
     *
     * @param  Collection<int, ShowStatistic>  $samples
     * @return array<int, array{label: string, value: int|null}>
     */
    private function points(Collection $samples, int $cap): array
    {
        if ($samples->isEmpty()) {
            return [];
        }

        $size = (int) max(1, ceil($samples->count() / $cap));

        return $samples
            ->chunk($size)
            ->map(fn (Collection $chunk) => [
                'label' => $this->label($chunk->first()->recorded_at),
                'value' => (int) round($chunk->avg('viewer_count')),
            ])
            ->values()
            ->all();
    }

    private function label(Carbon $at): string
    {
        return $at->format('H:i');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function viewers(Show $show): array
    {
        return $show->viewerSessions()
            ->with('user:id,name')
            ->orderByDesc('joined_at')
            ->limit(100)
            ->get()
            ->map(fn ($session) => [
                'id' => $session->id,
                'name' => $session->user?->name ?? 'Unknown',
                'joined_at' => $session->joined_at?->format('M j, H:i'),
                'left_at' => $session->left_at?->format('M j, H:i'),
                'duration' => $this->duration($session->watch_duration),
                'active' => (bool) $session->is_active,
            ])
            ->all();
    }

    /**
     * `1h 04m 12s`, matching the Filament viewers relation manager.
     */
    private function duration(?int $seconds): string
    {
        if (! $seconds) {
            return '—';
        }

        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $remaining = $seconds % 60;

        return match (true) {
            $hours > 0 => sprintf('%dh %dm %ds', $hours, $minutes, $remaining),
            $minutes > 0 => sprintf('%dm %ds', $minutes, $remaining),
            default => sprintf('%ds', $remaining),
        };
    }
}
