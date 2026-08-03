<?php

namespace App\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Http\Requests\Manage\ShowRequest;
use App\Models\Show;
use App\Models\Source;
use App\Services\PretalxService;
use App\Support\Manage\Action;
use App\Support\Manage\Column;
use App\Support\Manage\Filter;
use App\Support\Manage\Status;
use App\Support\Manage\Table;
use App\Support\Manage\Toast;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

class ShowController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Show::class);

        $table = Table::make(Show::query()->with('source'))
            ->name('shows')
            ->columns([
                Column::image('thumbnail', 'Thumbnail')->width('72px'),
                Column::text('title', 'Title')->searchable()->sortable(),
                Column::badge('source', 'Source')->searchable('source.name'),
                Column::badge('status', 'Status'),
                Column::datetime('scheduled_start', 'Scheduled')->sortable(),
                Column::datetime('actual_start', 'Went Live')
                    ->sortable()
                    ->fallback('Not started')
                    ->toggleable(),
                Column::number('viewer_count', 'Viewers')->sortable(),
                Column::number('peak_viewer_count', 'Peak')->sortable()->toggleable(hiddenByDefault: true),
                Column::badge('auto_mode', 'Auto'),
                Column::badge('access', 'Access')->toggleable(hiddenByDefault: true),
            ])
            ->filters([
                // On by default, as in Filament: an operator almost never wants the archive
                // in the way of today's running order.
                Filter::boolean('hide_ended', 'Hide ended')
                    ->default(true)
                    ->apply(fn (Builder $query) => $query->where('status', '!=', 'ended')),
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

    public function cancel(Show $show): RedirectResponse
    {
        $this->authorize('cancel', $show);

        $show->cancel();

        Toast::flashSuccess('Show cancelled', "'{$show->title}' will not be broadcast.");

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
                    'The show will not be broadcast. It stays on the schedule as cancelled.',
                    'Cancel Show',
                );
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
        if ($user->can('create', \App\Models\Recording::class) && $show->actual_start) {
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
