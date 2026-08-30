<?php

namespace App\Http\Controllers;

use App\Models\Show;
use App\Models\Source;
use App\Models\User;
use App\Support\Features;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class ScheduleController extends Controller
{
    /**
     * Programme guide: every channel's day laid out on a shared time axis.
     */
    public function index()
    {
        /** @var User|null $user */
        $user = Auth::user();

        $shows = Show::with('source')
            ->accessibleBy($user)
            ->notArchived()
            ->whereNotNull('scheduled_start')
            // Cancelled slots stay on the guide so anyone who planned around them
            // sees it is off rather than finding an empty gap.
            ->whereIn('status', ['scheduled', 'live', 'ended', 'cancelled'])
            // Today through the next six days, plus anything still on air that
            // started before midnight so a long show never drops off the guide.
            ->where(function ($query) {
                $query->whereBetween('scheduled_start', [
                    now()->startOfDay(),
                    now()->addDays(6)->endOfDay(),
                ])->orWhere(function ($query) {
                    $query->where('status', 'live')
                        ->where('scheduled_start', '>=', now()->subDay());
                });
            })
            ->orderBy('scheduled_start')
            ->get();

        // Channel order follows source priority so the primary channel is the top row.
        $sourceOrder = Source::ordered()->pluck('id')->values()->all();

        // Which of these the viewer already follows. One query for the page rather
        // than a flag resolved per block, which would be a query per slot.
        $followed = $user && Features::enabled('notifications')
            ? $user->showSubscriptions()->whereIn('show_id', $shows->pluck('id'))->pluck('show_id')->all()
            : [];

        $days = $shows
            ->groupBy(fn (Show $show) => $show->scheduled_start->toDateString())
            ->map(function ($dayShows, $date) use ($sourceOrder, $followed) {
                $day = $dayShows->first()->scheduled_start->copy()->startOfDay();

                $channels = $dayShows
                    ->groupBy('source_id')
                    ->map(fn ($channelShows) => [
                        'id' => $channelShows->first()->source_id,
                        'name' => $channelShows->first()->source?->name ?? 'Unassigned',
                        'shows' => $channelShows
                            ->sortBy('scheduled_start')
                            ->map(fn (Show $show) => [
                                'id' => $show->id,
                                'title' => $show->title,
                                'slug' => $show->slug,
                                'status' => $show->status,
                                'cancellation_reason' => $show->cancellation_reason,
                                'scheduled_start' => $show->scheduled_start->toIso8601String(),
                                // Blocks without an end time get a one hour default so the
                                // grid still has something to span.
                                'scheduled_end' => ($show->scheduled_end ?? $show->scheduled_start->copy()->addHour())->toIso8601String(),
                                'viewer_count' => $show->viewer_count,
                                'is_restricted' => $show->hasAccessRestriction(),
                                // Tells a viewer they can catch this later if they miss
                                // it. Purely a promise: capture happens for every source
                                // regardless, and whether a recording actually appears is
                                // decided by publishing it.
                                'will_be_available' => $show->willBeAvailable(),
                                'followed' => in_array($show->id, $followed, true),
                            ])
                            ->values(),
                    ])
                    ->sortBy(fn ($channel) => array_search($channel['id'], $sourceOrder, true) === false
                        ? PHP_INT_MAX
                        : array_search($channel['id'], $sourceOrder, true))
                    ->values();

                return [
                    'date' => $date,
                    'label' => $day->isToday() ? 'Today' : $day->format('D j M'),
                    'sub_label' => $day->format('D j M'),
                    'is_today' => $day->isToday(),
                    'channels' => $channels,
                ];
            })
            ->sortBy('date')
            ->values();

        return Inertia::render('Schedule', [
            'days' => $days,
            // Absent for a guest and for an installation with the feature off, so the
            // follow buttons are not rendered as something to sign in for.
            'canFollow' => (bool) $user && Features::enabled('notifications'),
            'primaryChannel' => Source::featured()?->name,
            'currentTime' => now()->toIso8601String(),
        ]);
    }
}
