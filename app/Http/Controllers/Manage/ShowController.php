<?php

namespace App\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Http\Requests\Manage\ShowRequest;
use App\Models\Recording;
use App\Models\Show;
use App\Models\Source;
use App\Services\PretalxService;
use App\Support\Manage\Action;
use App\Support\Manage\Column;
use App\Support\Manage\Filter;
use App\Support\Manage\InlineEdit;
use App\Support\Manage\Status;
use App\Support\Manage\Table;
use App\Support\Manage\Toast;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

class ShowController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Show::class);

        // Archiving widens the result set rather than narrowing it, so the exclusion sits
        // on the base query and the filter's job is to leave it off.
        $query = Show::query()->with('source');

        if (! $request->boolean('filter.show_archived')) {
            $query->notArchived();
        }

        $table = Table::make($query)
            ->name('shows')
            ->columns([
                Column::image('thumbnail', 'Thumbnail')->width('72px'),
                Column::text('title', 'Title')->searchable()->sortable(),
                Column::badge('source', 'Source')->searchable('source.name'),
                Column::badge('status', 'Status'),
                Column::datetime('scheduled_start', 'Scheduled')->sortable(),
                Column::datetime('scheduled_end', 'Ends')->sortable()->toggleable(),
                Column::datetime('actual_start', 'Went Live')
                    ->sortable()
                    ->fallback('Not started')
                    ->toggleable(),
                Column::number('viewer_count', 'Viewers')->sortable(),
                Column::number('peak_viewer_count', 'Peak')->sortable()->toggleable(hiddenByDefault: true),
                Column::badge('auto_mode', 'Auto'),
                Column::badge('access', 'Access')->toggleable(hiddenByDefault: true),
                Column::datetime('archived_at', 'Archived')
                    ->sortable()
                    ->fallback('Not archived')
                    ->toggleable(hiddenByDefault: true),
            ])
            ->filters([
                // On by default, as in Filament: an operator almost never wants the archive
                // in the way of today's running order.
                Filter::boolean('hide_ended', 'Hide ended')
                    ->default(true)
                    ->apply(fn (Builder $query) => $query->where('status', '!=', 'ended')),
                // No apply(): the archived rows are already off the base query above, and
                // ticking this box is what stops that happening.
                Filter::boolean('show_archived', 'Show archived'),
                Filter::select('status', 'Status')
                    ->options(array_combine(
                        ShowRequest::STATUSES,
                        array_map('ucfirst', ShowRequest::STATUSES),
                    ))
                    ->multiple(),
                Filter::select('source', 'Source')
                    ->options(Source::ordered()->pluck('name', 'id')->all())
                    ->apply(fn (Builder $query, string $value) => $query->where('source_id', $value)),
                Filter::boolean('today', 'Today')
                    ->apply(fn (Builder $query) => $query->today()),
                Filter::boolean('upcoming', 'Upcoming')
                    ->apply(fn (Builder $query) => $query->upcoming()),
            ])
            ->defaultSort('scheduled_start', 'asc')
            ->rows(fn (Show $show) => $this->row($show))
            ->recordUrl(fn (Show $show) => route('manage.shows.edit', $show))
            ->rowActions(fn (Show $show) => $this->recordActions($show, includeEdit: true))
            ->inlineEdit(fn (Show $show) => $this->inlineEdit($show))
            ->bulkActions($this->bulkActions())
            ->pageActions($this->pageActions());

        return inertia('Manage/Shows/Index', [
            'table' => $table->toArray($request),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Show::class);

        return inertia('Manage/Shows/Form', [
            'show' => null,
            'options' => $this->formOptions(),
            'defaults' => [
                'title' => '',
                'slug' => '',
                'source_id' => Source::ordered()->value('id'),
                'description' => '',
                'scheduled_start' => now()->format('Y-m-d\TH:i'),
                'scheduled_end' => now()->addHour()->format('Y-m-d\TH:i'),
                'actual_start' => null,
                'actual_end' => null,
                'auto_mode' => false,
                'auto_stop_at' => null,
                'announce_recording' => false,
                'visibility' => 'public',
                'required_roles' => [],
            ],
        ]);
    }

    public function store(ShowRequest $request): RedirectResponse
    {
        $this->authorize('create', Show::class);

        $show = Show::create($request->showData());

        Toast::flashSuccess('Show created', "'{$show->title}' is scheduled.");

        return to_route('manage.shows.edit', $show);
    }

    public function edit(Show $show): Response
    {
        $this->authorize('view', $show);

        return inertia('Manage/Shows/Form', [
            'show' => [
                'id' => $show->id,
                'title' => $show->title,
                'slug' => $show->slug,
                'source_id' => $show->source_id,
                'description' => $show->description,
                // datetime-local wants minutes and no timezone suffix.
                'scheduled_start' => $show->scheduled_start?->format('Y-m-d\TH:i'),
                'scheduled_end' => $show->scheduled_end?->format('Y-m-d\TH:i'),
                'actual_start' => $show->actual_start?->format('Y-m-d\TH:i:s'),
                'actual_end' => $show->actual_end?->format('Y-m-d\TH:i:s'),
                'auto_mode' => (bool) $show->auto_mode,
                'auto_stop_at' => $show->auto_stop_at?->format('Y-m-d\TH:i'),
                'announce_recording' => (bool) $show->announce_recording,
                'visibility' => $show->isPrivate() ? 'private' : 'public',
                'required_roles' => $show->required_roles ?? [],
                // Captured off the stream while it runs; never set by hand here. A
                // recording carries its own, separate thumbnail.
                'thumbnail_url' => $show->thumbnail_url,
                'status' => Status::show($show->status),
                'is_live' => $show->status === 'live',
                'viewer_count' => $show->viewer_count,
                'peak_viewer_count' => $show->peak_viewer_count,
                'formatted_duration' => $show->formatted_duration,
                'statistics_url' => route('manage.shows.statistics', $show),
            ],
            'options' => $this->formOptions(),
            'actions' => array_map(
                fn (Action $action) => $action->toArray(),
                $this->recordActions($show, includeEdit: false),
            ),
        ]);
    }

    public function update(ShowRequest $request, Show $show): RedirectResponse
    {
        $this->authorize('update', $show);

        $show->update($request->showData($show));

        Toast::flashSuccess('Show updated');

        return back();
    }

    public function destroy(Show $show): RedirectResponse
    {
        if (! request()->user()->can('delete', $show)) {
            Toast::flashDanger('Cannot delete live show', 'Please end the stream before deleting.');

            return back();
        }

        $title = $show->title;
        $show->delete();

        Toast::flashSuccess('Show deleted', "'{$title}' has been removed.");

        return to_route('manage.shows.index');
    }

    public function goLive(Show $show): RedirectResponse
    {
        $this->authorize('goLive', $show);

        $show->goLive();

        Toast::flashSuccess('Show is now live!', "'{$show->title}' is now streaming.");

        return back();
    }

    public function endStream(Show $show): RedirectResponse
    {
        $this->authorize('endStream', $show);

        $show->endLivestream();

        Toast::flashSuccess('Stream ended', "'{$show->title}' has ended.");

        return back();
    }

    public function cancel(Show $show, Request $request): RedirectResponse
    {
        $this->authorize('cancel', $show);

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:120'],
        ]);

        $show->cancel($validated['reason'] ?? null);

        Toast::flashSuccess('Show cancelled', "'{$show->title}' will not be broadcast.");

        return back();
    }

    public function archive(Show $show): RedirectResponse
    {
        $this->authorize('archive', $show);

        $show->archive();

        Toast::flashSuccess('Show archived', "'{$show->title}' is out of the default view.");

        return back();
    }

    public function unarchive(Show $show): RedirectResponse
    {
        $this->authorize('archive', $show);

        $show->unarchive();

        Toast::flashSuccess('Show restored', "'{$show->title}' is back in the default view.");

        return back();
    }

    /**
     * A live show cannot be filed away while it is on air; the rest of the batch still goes.
     */
    public function bulkArchive(Request $request): RedirectResponse
    {
        $this->authorize('create', Show::class);

        $validated = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer'],
        ]);

        $archived = Show::whereIn('id', $validated['ids'])
            ->whereNull('archived_at')
            ->where('status', '!=', 'live')
            ->get()
            ->each->archive()
            ->count();

        Toast::flashSuccess('Shows archived', $archived.' of '.count($validated['ids']).' were archived.');

        return back();
    }

    public function bulkUnarchive(Request $request): RedirectResponse
    {
        $this->authorize('create', Show::class);

        $validated = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer'],
        ]);

        $restored = Show::whereIn('id', $validated['ids'])
            ->whereNotNull('archived_at')
            ->get()
            ->each->unarchive()
            ->count();

        Toast::flashSuccess('Shows restored', $restored.' of '.count($validated['ids']).' were archived.');

        return back();
    }

    /**
     * Cancelling only applies to shows that have not started; anything else is skipped
     * rather than failing the whole batch, which is what Filament's bulk action did.
     */
    public function bulkCancel(Request $request): RedirectResponse
    {
        $this->authorize('create', Show::class);

        $validated = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer'],
        ]);

        $cancelled = Show::whereIn('id', $validated['ids'])
            ->where('status', 'scheduled')
            ->get()
            ->each->cancel()
            ->count();

        Toast::flashSuccess('Shows cancelled', $cancelled.' of '.count($validated['ids']).' were still scheduled.');

        return back();
    }

    /**
     * All-or-nothing: one live show in the selection blocks the batch, as in Filament.
     */
    public function bulkDestroy(Request $request): RedirectResponse
    {
        $this->authorize('create', Show::class);

        $validated = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer'],
        ]);

        $shows = Show::whereIn('id', $validated['ids'])->get();

        foreach ($shows as $show) {
            if (! $request->user()->can('delete', $show)) {
                Toast::flashDanger('Cannot delete shows', 'One or more shows are currently live.');

                return back();
            }
        }

        $shows->each->delete();

        Toast::flashSuccess('Shows deleted', $shows->count().' removed.');

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * What the running order gets edited for: which stage it is on, and when.
     *
     * Nothing else is offered here. Titles, access and auto mode are one-off decisions
     * made on the show's own page; the times and the source are what move all afternoon
     * while a programme is being reshuffled, and opening a form for each of them is the
     * whole cost of doing it.
     */
    private function inlineEdit(Show $show): ?InlineEdit
    {
        if (! request()->user()->can('update', $show)) {
            return null;
        }

        // Moving a running show onto another stage would cut the stream out from under
        // whoever is watching it. The times stay editable: an overrunning show having its
        // end pushed back is the ordinary case.
        $live = $show->status === 'live';

        return InlineEdit::patch(route('manage.shows.inline', $show))->fields([
            [
                'key' => 'source',
                'field' => 'source_id',
                'type' => 'select',
                'label' => 'Source',
                'value' => $show->source_id,
                'options' => $this->sourceOptions(),
                'disabled' => $live ? 'The show is on air; end it before moving it.' : null,
            ],
            [
                'key' => 'scheduled_start',
                'field' => 'scheduled_start',
                'type' => 'datetime',
                'label' => 'Scheduled start',
                'value' => $show->scheduled_start?->format('Y-m-d\TH:i'),
            ],
            [
                'key' => 'scheduled_end',
                'field' => 'scheduled_end',
                'type' => 'datetime',
                'label' => 'Scheduled end',
                'value' => $show->scheduled_end?->format('Y-m-d\TH:i'),
            ],
        ]);
    }

    /**
     * One field of one show, saved on its own from the list.
     *
     * Everything is `sometimes`: the client sends the field that changed and nothing
     * else, so a missing key means "leave it", not "clear it". The pair rule reads the
     * stored value for whichever half was not sent, which is what stops an end time
     * being dragged in front of a start time that is not on screen.
     */
    public function inlineUpdate(Request $request, Show $show): RedirectResponse
    {
        $this->authorize('update', $show);

        $validated = $request->validate([
            'source_id' => ['sometimes', 'integer', 'exists:sources,id'],
            'scheduled_start' => ['sometimes', 'required', 'date'],
            'scheduled_end' => ['sometimes', 'required', 'date'],
        ]);

        $start = isset($validated['scheduled_start'])
            ? CarbonImmutable::parse($validated['scheduled_start'])
            : $show->scheduled_start;
        $end = isset($validated['scheduled_end'])
            ? CarbonImmutable::parse($validated['scheduled_end'])
            : $show->scheduled_end;

        if ($start && $end && $end->lessThanOrEqualTo($start)) {
            Toast::flashDanger('Not saved', 'The scheduled end must be later than the scheduled start.');

            return back();
        }

        if (isset($validated['source_id']) && $show->status === 'live') {
            Toast::flashDanger('Not saved', 'The show is on air; end it before moving it to another source.');

            return back();
        }

        /*
         * The hard stop tracks the scheduled end unless someone has moved it by hand, as
         * on the form. Without this, pushing an overrunning show's end back in auto mode
         * would leave the stop where it was and cut it off anyway.
         */
        if (
            isset($validated['scheduled_end'])
            && $show->auto_mode
            && $show->auto_stop_at
            && $show->scheduled_end
            && $show->auto_stop_at->equalTo($show->scheduled_end)
        ) {
            $validated['auto_stop_at'] = $validated['scheduled_end'];
        }

        $show->update($validated);

        Toast::flashSuccess('Show updated', "'{$show->title}' saved.");

        return back();
    }

    /**
     * @return array<int, array{value: int, label: string}>
     */
    private function sourceOptions(): array
    {
        return Source::ordered()
            ->get(['id', 'name'])
            ->map(fn (Source $source) => ['value' => $source->id, 'label' => $source->name])
            ->all();
    }

    private function row(Show $show): array
    {
        return [
            'thumbnail' => $show->thumbnail_url,
            'title' => $show->title,
            'source' => $show->source
                ? Status::make($show->source->name, Status::INFO)
                : null,
            'status' => Status::show($show->status),
            'scheduled_start' => $show->scheduled_start?->format('M j, Y H:i'),
            'scheduled_end' => $show->scheduled_end?->format('M j, Y H:i'),
            'archived_at' => $show->archived_at?->format('M j, Y H:i'),
            'actual_start' => $show->actual_start?->format('M j, Y H:i'),
            'viewer_count' => $show->viewer_count,
            'peak_viewer_count' => $show->peak_viewer_count,
            'auto_mode' => Status::toggle(
                (bool) $show->auto_mode,
                'Auto',
                'Manual',
                trueTone: Status::OK,
                falseTone: Status::IDLE,
                trueIcon: 'cog',
                falseIcon: 'hand',
            ),
            'access' => Status::toggle(
                $show->hasAccessRestriction(),
                'Restricted',
                'Public',
                trueTone: Status::WARN,
                falseTone: Status::OK,
                trueIcon: 'lock',
                falseIcon: 'globe',
            ),
        ];
    }

    /**
     * @return array<int, Action>
     */
    private function recordActions(Show $show, bool $includeEdit): array
    {
        $user = request()->user();
        $actions = [];

        if ($includeEdit) {
            $actions[] = Action::link('edit', 'Edit', route('manage.shows.edit', $show))->icon('pencil');
        }

        if ($user->can('goLive', $show)) {
            $actions[] = Action::post('go_live', 'Go Live', route('manage.shows.go-live', $show))
                ->icon('play')
                ->tone(Status::OK)
                ->confirm(
                    'Start Live Stream',
                    'Are you sure you want to start this show? This will mark it as live and notify viewers.',
                    'Go Live',
                );
        }

        if ($user->can('cancel', $show)) {
            $actions[] = Action::post('cancel', 'Cancel', route('manage.shows.cancel', $show))
                ->icon('circle-x')
                ->tone(Status::IDLE)
                ->confirm(
                    'Cancel Show',
                    'The show will not be broadcast. It stays on the schedule marked cancelled.',
                    'Cancel Show',
                )
                ->fields([[
                    'key' => 'reason',
                    'label' => 'Reason (optional)',
                    'type' => 'text',
                    'required' => false,
                    'helper' => 'Shown to viewers on the schedule, e.g. "no stream, technical issue". Leave empty for a plain cancellation.',
                ]]);
        }

        if ($user->can('endStream', $show)) {
            $actions[] = Action::post('end_stream', 'End Stream', route('manage.shows.end', $show))
                ->icon('square')
                ->tone(Status::DANGER)
                ->confirm(
                    'End Live Stream',
                    'Are you sure you want to end this show? This will stop the stream and disconnect all viewers.',
                    'End Stream',
                );
        }

        // Cutting a recording deliberately does not wait for the show to end. The archive
        // is a continuous per-source timeline, so any range can be cut while the show is
        // still running, which is the only workable option for a source that stays online
        // for the whole event. What it does need is an end marker, so the action is only
        // offered once one exists.
        if ($user->can('create', Recording::class) && $show->actual_start) {
            $actions[] = Action::post('create_recording', 'Create Recording', route('manage.shows.recording.store', $show))
                ->icon('film')
                ->disabled($show->actual_end
                    ? null
                    : 'Still live. End the show, or set an end marker on the recording.')
                ->confirm(
                    'Create recording',
                    "Cuts '{$show->title}' from the archive as an unpublished draft. "
                    .'The markers can be adjusted afterwards and the playlist is rebuilt each time.',
                    'Create draft',
                );
        }

        $actions[] = Action::link('statistics', 'View Statistics', route('manage.shows.statistics', $show))
            ->icon('bar-chart');

        if ($user->can('archive', $show)) {
            $actions[] = $show->isArchived()
                ? Action::post('unarchive', 'Restore', route('manage.shows.unarchive', $show))
                    ->icon('archive-restore')
                    ->tone(Status::IDLE)
                : Action::post('archive', 'Archive', route('manage.shows.archive', $show))
                    ->icon('archive')
                    ->tone(Status::IDLE)
                    ->confirm(
                        'Archive show',
                        "'{$show->title}' is hidden from the shows list and the schedule until 'Show archived' is ticked.",
                        'Archive',
                    );
        }

        if ($user->can('update', $show)) {
            $actions[] = Action::delete('delete', 'Delete', route('manage.shows.destroy', $show))
                ->icon('trash-2')
                ->tone(Status::DANGER)
                ->disabled($show->status === 'live' ? 'End the stream before deleting.' : null)
                ->confirm('Delete show', "'{$show->title}' and its viewer history are removed.", 'Delete');
        }

        return $actions;
    }

    /**
     * @return array<int, Action>
     */
    private function bulkActions(): array
    {
        if (! request()->user()->can('create', Show::class)) {
            return [];
        }

        return [
            Action::post('bulk_cancel', 'Cancel Shows', route('manage.shows.bulk.cancel'))
                ->icon('circle-x')
                ->tone(Status::IDLE)
                ->confirm('Cancel selected shows', 'Only shows that have not started yet are cancelled.', 'Cancel shows'),
            Action::post('bulk_archive', 'Archive', route('manage.shows.bulk.archive'))
                ->icon('archive')
                ->tone(Status::IDLE)
                ->confirm('Archive selected shows', 'Live shows are skipped. Archived shows stay out of every current view.', 'Archive'),
            Action::post('bulk_unarchive', 'Restore', route('manage.shows.bulk.unarchive'))
                ->icon('archive-restore')
                ->tone(Status::IDLE)
                ->confirm('Restore selected shows', 'They return to the default shows list and the schedule.', 'Restore'),
            Action::delete('bulk_delete', 'Delete', route('manage.shows.bulk.destroy'))
                ->icon('trash-2')
                ->tone(Status::DANGER)
                ->confirm('Delete selected shows', 'A live show in the selection blocks the whole batch.', 'Delete'),
        ];
    }

    /**
     * @return array<int, Action>
     */
    private function pageActions(): array
    {
        $actions = [];

        if (request()->user()->can('create', Show::class)) {
            // Only offered once there is an instance and an event to import from;
            // otherwise the button leads to a screen that can only say "not configured".
            if (app(PretalxService::class)->isConfigured()) {
                $actions[] = Action::link('import', 'Import from pretalx', route('manage.shows.import'))
                    ->icon('download');
            }

            $actions[] = Action::link('create', 'New Show', route('manage.shows.create'))->icon('plus');
        }

        return $actions;
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(): array
    {
        return [
            'sources' => Source::ordered()
                ->get(['id', 'name'])
                ->map(fn (Source $source) => ['value' => $source->id, 'label' => $source->name])
                ->all(),
            'statuses' => array_map(
                fn (string $status) => ['value' => $status, 'label' => ucfirst($status)],
                ShowRequest::STATUSES,
            ),
            'roles' => ShowRequest::roleOptions(),
        ];
    }
}
