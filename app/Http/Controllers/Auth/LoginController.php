<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Show;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Collection;
use Inertia\Inertia;

class LoginController extends Controller
{
    /**
     * How far ahead the schedule rail on the login screen looks.
     */
    private const SCHEDULE_WINDOW_HOURS = 20;

    /**
     * How many rows it shows, however much fits in that window.
     */
    private const SCHEDULE_LENGTH = 6;

    public function __invoke()
    {
        return Inertia::render('Auth/Login', [
            'schedule' => $this->schedule(),
        ]);
    }

    /**
     * Whatever is on now, plus anything starting in the next 20 hours, in clock
     * order. Ended and cancelled shows never appear.
     */
    private function schedule(): Collection
    {
        return Show::query()
            // accessibleBy(null) keeps role-restricted shows out of the rail,
            // which is rendered before anyone has signed in.
            ->accessibleBy(null)
            ->with('source')
            ->where(function (Builder $query) {
                // Live shows stay listed no matter when they were scheduled to
                // start, since they are on right now.
                $query->where('status', 'live')
                    ->orWhere(fn (Builder $upcoming) => $upcoming
                        ->where('status', 'scheduled')
                        ->whereBetween('scheduled_start', [
                            now(),
                            now()->addHours(self::SCHEDULE_WINDOW_HOURS),
                        ]));
            })
            // Live shows sort by when they actually started, so a stream that has
            // been running since yesterday stays above tonight's line-up.
            ->orderByRaw('COALESCE(actual_start, scheduled_start)')
            ->limit(self::SCHEDULE_LENGTH)
            ->get()
            ->map(fn (Show $show) => [
                'id' => $show->id,
                'title' => $show->title,
                'source' => $show->source?->name,
                'time' => ($show->actual_start ?? $show->scheduled_start)?->format('H:i'),
                // Drives both the highlighted row and the LIVE marker.
                'current' => $show->status === 'live',
            ])
            ->values();
    }
}
