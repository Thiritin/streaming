<?php

namespace App\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Models\Show;
use App\Models\Source;
use App\Support\Manage\Status;
use App\Support\Manage\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Inertia\Response;

/**
 * The running order as a day: sources across the top, hours down the side, shows as
 * blocks in the columns.
 *
 * The table answers "what is the state of this show"; the planner answers "what does the
 * day look like, and what collides". Blocks are moved and resized here; everything that is
 * not a time (access, auto mode, recording) stays on the form.
 *
 * A day at a time, not a week. An event's programme is dense inside a day and empty
 * between them, and a week-wide track draws mostly the empty part. It is reached from
 * the Shows page rather than the rail, and renders without it, because laying out a
 * day wants the whole window.
 */
class ShowPlannerController extends Controller
{
    /**
     * Rows are an hour tall at this scale, and a show is placed by the minute.
     */
    private const PX_PER_HOUR = 64;

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Show::class);

        $day = $this->day($request);
        $end = $day->clone()->addDay();

        $shows = Show::query()
            ->with('source')
            ->notArchived()
            ->whereNotNull('scheduled_start')
            // Overlap, not containment: a dance running past midnight has to appear on the
            // day it starts even when the window ends mid-show.
            ->where('scheduled_start', '<', $end)
            ->where(function ($query) use ($day) {
                $query->where('scheduled_end', '>', $day)
                    ->orWhereNull('scheduled_end');
            })
            ->get();

        $grouped = $shows->groupBy('source_id');

        return inertia('Manage/Shows/Planner', [
            'day' => [
                'iso' => $day->toDateString(),
                'label' => $day->isoFormat('dddd, D MMMM YYYY'),
                'isToday' => $day->isToday(),
                'previous' => $day->clone()->subDay()->toDateString(),
                'next' => $day->clone()->addDay()->toDateString(),
                'start' => $day->toIso8601String(),
            ],
            'now' => now()->toIso8601String(),
            'pxPerHour' => self::PX_PER_HOUR,
            'columns' => Source::ordered()
                ->get(['id', 'name'])
                ->map(fn (Source $source) => [
                    'id' => $source->id,
                    'name' => $source->name,
                    'shows' => $grouped->get($source->id, collect())
                        ->sortBy('scheduled_start')
                        ->map(fn (Show $show) => $this->block($show))
                        ->values()
                        ->all(),
                ])
                ->all(),
            /*
             * Which hours are worth drawing. A convention day starts late morning and
             * runs to midnight; opening on 00:00 buries the programme below the fold.
             * The client can still ask for the full day.
             */
            'hours' => $this->hourWindow($shows, $day),
            'closeUrl' => route('manage.shows.index'),
            'can' => [
                'edit' => $request->user()->can('create', Show::class),
            ],
        ]);
    }

    /**
     * The first and last hour to draw: the day's own programme, an hour of air either
     * side, and never narrower than a working afternoon.
     *
     * @param  Collection<int, Show>  $shows
     * @return array{from: int, to: int}
     */
    private function hourWindow($shows, Carbon $day): array
    {
        if ($shows->isEmpty()) {
            return ['from' => 8, 'to' => 24];
        }

        $starts = $shows->map(fn (Show $show) => max($show->scheduled_start, $day));
        $ends = $shows->map(fn (Show $show) => min(
            $show->scheduled_end ?? $show->scheduled_start->clone()->addHour(),
            $day->clone()->addDay(),
        ));

        $from = (int) floor($starts->min()->diffInMinutes($day, absolute: true) / 60) - 1;
        $to = (int) ceil($day->diffInMinutes($ends->max(), absolute: true) / 60) + 1;

        return [
            'from' => max(0, min($from, 20)),
            'to' => min(24, max($to, $from + 4)),
        ];
    }

    /**
     * Move or resize one block. Only the times: everything else keeps its value.
     */
    public function reschedule(Request $request, Show $show): RedirectResponse
    {
        $this->authorize('update', $show);

        $validated = $request->validate([
            'scheduled_start' => ['required', 'date'],
            'scheduled_end' => ['required', 'date', 'after:scheduled_start'],
        ]);

        $show->update($validated);

        Toast::flashSuccess(
            'Show rescheduled',
            "'{$show->title}' now runs ".$show->scheduled_start->format('D j M H:i').
            ' to '.$show->scheduled_end->format('H:i').'.',
        );

        return back();
    }

    /**
     * Quick-create from an empty stretch of track: enough to hold the slot, nothing more.
     * Access, auto mode and recording are decided on the form afterwards.
     */
    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Show::class);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'source_id' => ['required', 'integer', 'exists:sources,id'],
            'scheduled_start' => ['required', 'date'],
            'scheduled_end' => ['required', 'date', 'after:scheduled_start'],
        ]);

        // The model's creating hook builds the dated slug from title + scheduled_start.
        $show = Show::create($validated + [
            'status' => 'scheduled',
            'auto_mode' => false,
            'required_roles' => [],
        ]);

        Toast::flashSuccess('Show created', "'{$show->title}' holds the slot. Open it to finish setting it up.");

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function block(Show $show): array
    {
        // A show with no end has no length to draw, so it gets a nominal hour - the same
        // fallback the public schedule uses.
        $end = $show->scheduled_end ?? $show->scheduled_start->clone()->addHour();

        return [
            'id' => $show->id,
            'title' => $show->title,
            'start' => $show->scheduled_start->toIso8601String(),
            'end' => $end->toIso8601String(),
            'status' => Status::show($show->status),
            // A live show must not be dragged out from under its viewers.
            'locked' => $show->status === 'live',
            'autoMode' => (bool) $show->auto_mode,
            'url' => route('manage.shows.edit', $show),
        ];
    }

    private function day(Request $request): Carbon
    {
        $raw = $request->string('date')->toString();

        if ($raw !== '') {
            try {
                return Carbon::parse($raw)->startOfDay();
            } catch (\Throwable) {
                // Fall through to today rather than 500 on a hand-edited query string.
            }
        }

        return now()->startOfDay();
    }
}
