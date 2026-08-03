<?php

namespace App\Http\Controllers\Manage;

use App\Enum\SourceStatusEnum;
use App\Http\Controllers\Controller;
use App\Http\Requests\Manage\SourceRequest;
use App\Models\Source;
use App\Support\Manage\Action;
use App\Support\Manage\Column;
use App\Support\Manage\Filter;
use App\Support\Manage\Status;
use App\Support\Manage\Table;
use App\Support\Manage\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Response;

class SourceController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Source::class);

        $table = Table::make(Source::query()->withCount('shows'))
            ->name('sources')
            ->columns([
                Column::badge('status', 'Status'),
                Column::text('name', 'Name')->searchable()->sortable(),
                Column::copyable('slug', 'Stream Name')->searchable()->sortable(),
                Column::number('priority', 'Priority')->sortable(),
                Column::number('shows_count', 'Total Shows')->sortable('shows_count'),
                Column::number('live_shows_count', 'Live Now'),
                Column::datetime('created_at', 'Created')->sortable()->toggleable(hiddenByDefault: true),
                Column::datetime('updated_at', 'Updated')->sortable()->toggleable(hiddenByDefault: true),
            ])
            ->filters([
                Filter::select('status', 'Status')
                    ->options($this->statusOptions())
                    ->placeholder('All statuses'),
            ])
            ->defaultSort('priority', 'desc')
            ->rows(fn (Source $source) => $this->row($source))
            ->recordUrl(fn (Source $source) => route('manage.sources.edit', $source))
            ->rowActions(fn (Source $source) => $this->rowActions($source))
            ->bulkActions($this->bulkActions())
            ->pageActions($this->pageActions());

        return inertia('Manage/Sources/Index', [
            'table' => $table->toArray($request),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Source::class);

        return inertia('Manage/Sources/Form', [
            'source' => null,
            'options' => ['statuses' => $this->statusOptionList()],
            /*
             * No `status` here: a new source starts offline (the column default) and is
             * moved only by the Update Status action.
             */
            'defaults' => [
                'name' => '',
                'slug' => '',
                'priority' => 0,
                'description' => '',
            ],
        ]);
    }

    public function store(SourceRequest $request): RedirectResponse
    {
        $this->authorize('create', Source::class);

        // The model boot hook generates the stream key, so it is never posted from a form.
        $source = Source::create($request->validated());

        Toast::flashSuccess('Source created', "'{$source->name}' is ready to receive a stream.");

        return to_route('manage.sources.edit', $source);
    }

    public function edit(Source $source): Response
    {
        $this->authorize('view', $source);

        return inertia('Manage/Sources/Form', [
            'source' => [
                'id' => $source->id,
                'name' => $source->name,
                'slug' => $source->slug,
                'status' => $source->status?->value,
                'priority' => $source->priority,
                'description' => $source->description,
                'rtmp_url' => $source->getRtmpServerUrl(),
                'stream_key' => $source->getObsStreamKey(),
                'shows_count' => $source->shows()->count(),
                'live_shows_count' => $source->liveShows()->count(),
                'created_at' => $source->created_at?->diffForHumans() ?? '-',
                'updated_at' => $source->updated_at?->diffForHumans() ?? '-',
            ],
            'options' => ['statuses' => $this->statusOptionList()],
            'actions' => array_map(
                fn (Action $action) => $action->toArray(),
                $this->recordActions($source),
            ),
            'shows' => $source->shows()
                ->orderByDesc('scheduled_start')
                ->limit(50)
                ->get()
                ->map(fn ($show) => [
                    'id' => $show->id,
                    'title' => $show->title,
                    'status' => Status::show($show->status),
                    'scheduled_start' => $show->scheduled_start?->format('M j, Y H:i'),
                    'viewer_count' => $show->viewer_count,
                    'url' => route('manage.shows.edit', $show),
                ])
                ->all(),
        ]);
    }

    public function update(SourceRequest $request, Source $source): RedirectResponse
    {
        $this->authorize('update', $source);

        $source->update($request->validated());

        Toast::flashSuccess('Source updated');

        return back();
    }

    public function destroy(Source $source): RedirectResponse
    {
        // SourcePolicy refuses while the source has a live show.
        if (! request()->user()->can('delete', $source)) {
            Toast::flashDanger('Cannot delete source', 'This source has active live shows.');

            return back();
        }

        $name = $source->name;
        $source->delete();

        Toast::flashSuccess('Source deleted', "'{$name}' has been removed.");

        return to_route('manage.sources.index');
    }

    /**
     * The observer broadcasts the change, so nothing else has to be nudged here.
     */
    public function updateStatus(Request $request, Source $source): RedirectResponse
    {
        $this->authorize('update', $source);

        $validated = $request->validate([
            'status' => ['required', Rule::enum(SourceStatusEnum::class)],
        ]);

        $source->update(['status' => $validated['status']]);

        Toast::flashSuccess(
            'Status updated',
            "Source '{$source->name}' status has been updated to {$validated['status']}.",
        );

        return back();
    }

    /**
     * @param  array<string, mixed>  $ids
     */
    public function bulkUpdateStatus(Request $request): RedirectResponse
    {
        $this->authorize('create', Source::class);

        $validated = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer'],
            'status' => ['required', Rule::enum(SourceStatusEnum::class)],
        ]);

        Source::whereIn('id', $validated['ids'])
            ->get()
            // One update per model rather than a mass update, so the observer fires and
            // each change is broadcast.
            ->each(fn (Source $source) => $source->update(['status' => $validated['status']]));

        Toast::flashSuccess('Status updated', 'The selected sources have been updated.');

        return back();
    }

    /**
     * All-or-nothing, matching Filament: if any selected source is live, none are deleted.
     */
    public function bulkDestroy(Request $request): RedirectResponse
    {
        $this->authorize('create', Source::class);

        $validated = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer'],
        ]);

        $sources = Source::whereIn('id', $validated['ids'])->get();

        foreach ($sources as $source) {
            if (! $request->user()->can('delete', $source)) {
                Toast::flashDanger('Cannot delete sources', 'One or more sources have active live shows.');

                return back();
            }
        }

        $sources->each->delete();

        Toast::flashSuccess('Sources deleted', $sources->count().' removed.');

        return back();
    }

    /**
     * Invalidates the key anyone is currently pushing with, so it is a confirmed action.
     */
    public function regenerateStreamKey(Source $source): RedirectResponse
    {
        $this->authorize('regenerateStreamKey', $source);

        $source->update(['stream_key' => Str::random(32)]);

        Toast::flashSuccess(
            'Stream key regenerated',
            'The new stream key has been saved and is now active.',
        );

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Source $source): array
    {
        return [
            'status' => Status::source($source->status),
            'name' => $source->name,
            'slug' => $source->slug,
            'priority' => $source->priority,
            'shows_count' => $source->shows_count,
            'live_shows_count' => (function () use ($source) {
                $live = $source->liveShows()->count();

                return ['display' => (string) $live, 'description' => $live > 0 ? 'on air' : null];
            })(),
            'created_at' => $source->created_at?->format('M j, Y H:i'),
            'updated_at' => $source->updated_at?->format('M j, Y H:i'),
        ];
    }

    /**
     * What a table row offers: open it, or remove it.
     *
     * Overriding a status and rotating a stream key both belong to one source and both have
     * consequences beyond the row - a rotated key disconnects whoever is pushing. They live
     * on the detail page, where the OBS block they affect is on screen.
     *
     * @return array<int, Action>
     */
    private function rowActions(Source $source): array
    {
        $actions = [
            Action::link('edit', 'Edit', route('manage.sources.edit', $source))->icon('pencil'),
        ];

        if (request()->user()->can('update', $source)) {
            $actions[] = $this->deleteAction($source);
        }

        return $actions;
    }

    /**
     * The detail page header: everything that acts on this one source.
     *
     * @return array<int, Action>
     */
    private function recordActions(Source $source): array
    {
        $user = request()->user();
        $actions = [];

        if ($user->can('update', $source)) {
            $actions[] = Action::post('update_status', 'Update Status', route('manage.sources.status', $source))
                ->icon('refresh-cw')
                ->tone(Status::WARN)
                ->fields([[
                    'key' => 'status',
                    'label' => 'New Status',
                    'type' => 'select',
                    'default' => $source->status?->value,
                    'required' => true,
                    'options' => $this->statusOptionList(),
                ]]);
        }

        if ($user->can('regenerateStreamKey', $source)) {
            $actions[] = Action::post('regenerate_key', 'Regenerate Stream Key', route('manage.sources.stream-key', $source))
                ->icon('refresh-cw')
                ->tone(Status::WARN)
                ->confirm(
                    'Regenerate Stream Key?',
                    'This will invalidate the current stream key. Any active streams will be disconnected.',
                    'Regenerate',
                );
        }

        if ($user->can('update', $source)) {
            $actions[] = $this->deleteAction($source);
        }

        return $actions;
    }

    /**
     * Offered even while blocked, carrying the reason, so the UI can explain itself.
     */
    private function deleteAction(Source $source): Action
    {
        $live = $source->liveShows()->exists();

        return Action::delete('delete', 'Delete', route('manage.sources.destroy', $source))
            ->icon('trash-2')
            ->tone(Status::DANGER)
            ->disabled($live ? 'This source has active live shows.' : null)
            ->confirm(
                'Delete source',
                "Deleting '{$source->name}' also removes the shows attached to it.",
                'Delete',
            );
    }

    /**
     * @return array<int, Action>
     */
    private function bulkActions(): array
    {
        if (! request()->user()->can('create', Source::class)) {
            return [];
        }

        return [
            Action::post('bulk_status', 'Update Status', route('manage.sources.bulk.status'))
                ->icon('refresh-cw')
                ->tone(Status::WARN)
                ->fields([[
                    'key' => 'status',
                    'label' => 'New Status',
                    'type' => 'select',
                    'default' => SourceStatusEnum::OFFLINE->value,
                    'required' => true,
                    'options' => $this->statusOptionList(),
                ]]),
            Action::delete('bulk_delete', 'Delete', route('manage.sources.bulk.destroy'))
                ->icon('trash-2')
                ->tone(Status::DANGER)
                ->confirm('Delete selected sources', 'Sources with a live show block the whole batch.', 'Delete'),
        ];
    }

    /**
     * @return array<int, Action>
     */
    private function pageActions(): array
    {
        if (! request()->user()->can('create', Source::class)) {
            return [];
        }

        return [
            Action::link('create', 'New Source', route('manage.sources.create'))->icon('plus'),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function statusOptions(): array
    {
        return [
            SourceStatusEnum::ONLINE->value => 'Online',
            SourceStatusEnum::OFFLINE->value => 'Offline',
            SourceStatusEnum::ERROR->value => 'Error',
        ];
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private function statusOptionList(): array
    {
        return collect($this->statusOptions())
            ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
            ->values()
            ->all();
    }
}
