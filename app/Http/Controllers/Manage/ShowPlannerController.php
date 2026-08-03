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
use Inertia\Response;

/**
 * The running order as a timeline: one continuous track per source across the whole event.
 *
 * The table answers "what is the state of this show"; the planner answers "what does the
 * day look like, and what collides". Blocks are moved and resized here; everything that is
 * not a time (access, auto mode, recording) stays on the form.
 */
class ShowPlannerController extends Controller
{
    /**
     * Bounds on the window, so a stray query string cannot ask for a decade of tracks.
     */
    private const DEFAULT_DAYS = 4;

    private const MAX_DAYS = 31;

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Show::class);

        $from = $this->from($request);
        $days = $this->days($request);
        $to = $from->clone()->addDays($days);

        $shows = Show::query()
            ->with('source')
            ->whereNotNull('scheduled_start')
            // Overlap, not containment: a dance running past midnight has to appear on the
            // day it starts even when the window ends mid-show.
            ->where('scheduled_start', '<', $to)
            ->where(function ($query) use ($from) {
                $query->where('scheduled_end', '>', $from)
                    ->orWhereNull('scheduled_end');
            })
            ->get();

        $grouped = $shows->groupBy('source_id');

        return inertia('Manage/Shows/Planner', [
            'range' => [
                'from' => $from->toIso8601String(),
                'to' => $to->toIso8601String(),
                'days' => $days,
                // Pre-formatted so the client never has to guess at locale or timezone.
                'dayLabels' => collect(range(0, $days - 1))
                    ->map(fn (int $offset) => [
                        'iso' => $from->clone()->addDays($offset)->toIso8601String(),
                        'label' => $from->clone()->addDays($offset)->format('D j M'),
                        'isToday' => $from->clone()->addDays($offset)->isToday(),
                    ])
                    ->all(),
            ],
            'now' => now()->toIso8601String(),
            'lanes' => Source::ordered()
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
            'can' => [
                'edit' => $request->user()->can('create', Show::class),
            ],
        ]);
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
            'announce_recording' => false,
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

    private function from(Request $request): Carbon
    {
        $raw = $request->string('from')->toString();

        if ($raw !== '') {
            try {
                return Carbon::parse($raw)->startOfDay();
            } catch (\Throwable) {
                // Fall through to today rather than 500 on a hand-edited query string.
            }
        }

        return now()->startOfDay();
    }

    private function days(Request $request): int
    {
        $days = (int) $request->input('days', self::DEFAULT_DAYS);

        return max(1, min($days, self::MAX_DAYS));
    }
}
