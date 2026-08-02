<?php

namespace App\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Http\Requests\Manage\RecordingRequest;
use App\Jobs\ProcessRecordingJob;
use App\Models\Recording;
use App\Models\Role;
use App\Models\Show;
use App\Support\Manage\Action;
use App\Support\Manage\Column;
use App\Support\Manage\Filter;
use App\Support\Manage\Status;
use App\Support\Manage\Table;
use App\Support\Manage\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * On-demand recordings: the HLS playlist, who may watch it, and the thumbnail.
 *
 * The thumbnail is captured from the playlist by ProcessRecordingJob when none is
 * uploaded, which is why "regenerate" is a matter of clearing the path and
 * dispatching the job again.
 */
class RecordingController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Recording::class);

        $table = Table::make(Recording::query()->with('show'))
            ->name('recordings')
            ->columns([
                Column::image('thumbnail', 'Thumbnail'),
                Column::text('title', 'Title')->searchable()->sortable(),
                Column::copyable('slug', 'Slug')->searchable()->toggleable(hiddenByDefault: true),
                Column::text('show', 'Show')->toggleable(),
                Column::datetime('date', 'Date')->sortable(),
                Column::duration('duration', 'Duration'),
                Column::number('views', 'Views')->sortable(),
                Column::badge('is_published', 'Published'),
                Column::badge('access', 'Access')->toggleable(hiddenByDefault: true),
            ])
            ->filters([
                Filter::ternary('is_published', 'Published')
                    ->trueLabel('Published only')
                    ->falseLabel('Unpublished only')
                    ->placeholder('All recordings'),
            ])
            ->defaultSort('date', 'desc')
            ->rows(fn (Recording $recording) => $this->row($recording))
            ->recordUrl(fn (Recording $recording) => route('manage.recordings.edit', $recording))
            ->rowActions(fn (Recording $recording) => $this->rowActions($recording))
            ->bulkActions($this->bulkActions())
            ->pageActions($this->pageActions());

        return inertia('Manage/Recordings/Index', [
            'table' => $table->toArray($request),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Recording::class);

        return inertia('Manage/Recordings/Form', [
            'recording' => null,
            'options' => [
                'shows' => $this->showOptions(),
                'roles' => $this->roleOptions(),
            ],
            'defaults' => [
                'show_id' => '',
                'title' => '',
                'slug' => '',
                'description' => '',
                'date' => now()->format('Y-m-d\TH:i'),
                'duration' => '',
                'm3u8_url' => '',
                'thumbnail_path' => '',
                'is_published' => true,
                'required_roles' => [],
            ],
        ]);
    }

    public function store(RecordingRequest $request): RedirectResponse
    {
        $this->authorize('create', Recording::class);

        $recording = Recording::create($request->validated());

        // Fills in whatever was left blank: duration from the playlist, and a
        // thumbnail from the first frame when none was uploaded.
        ProcessRecordingJob::dispatch($recording);

        Toast::flashSuccess('Recording created', "'{$recording->title}' is being processed.");

        return to_route('manage.recordings.edit', $recording);
    }

    public function edit(Recording $recording): Response
    {
        $this->authorize('view', $recording);

        return inertia('Manage/Recordings/Form', [
            'recording' => [
                'id' => $recording->id,
                'show_id' => $recording->show_id,
                'title' => $recording->title,
                'slug' => $recording->slug,
                'description' => $recording->description,
                'date' => $recording->date?->format('Y-m-d\TH:i'),
                'duration' => $recording->duration,
                'm3u8_url' => $recording->m3u8_url,
                'thumbnail_path' => $recording->thumbnail_path,
                'thumbnail_url' => $recording->thumbnail_url,
                'thumbnail_error' => $recording->thumbnail_capture_error,
                'is_published' => (bool) $recording->is_published,
                'required_roles' => $recording->required_roles ?? [],
                'views' => $recording->views,
            ],
            'options' => [
                'shows' => $this->showOptions(),
                'roles' => $this->roleOptions(),
            ],
            'actions' => array_map(
                fn (Action $action) => $action->toArray(),
                $this->recordActions($recording),
            ),
        ]);
    }

    public function update(RecordingRequest $request, Recording $recording): RedirectResponse
    {
        $this->authorize('update', $recording);

        $recording->update($request->validated());

        Toast::flashSuccess('Recording updated');

        return back();
    }

    public function destroy(Recording $recording): RedirectResponse
    {
        $this->authorize('delete', $recording);

        $title = $recording->title;
        $recording->delete();

        Toast::flashSuccess('Recording deleted', "'{$title}' has been removed.");

        return to_route('manage.recordings.index');
    }

    /**
     * Clearing the path is what makes the job capture a new frame rather than skip
     * a recording that already has one.
     */
    public function regenerateThumbnail(Recording $recording): RedirectResponse
    {
        $this->authorize('update', $recording);

        $recording->update([
            'thumbnail_path' => null,
            'thumbnail_capture_error' => null,
        ]);

        ProcessRecordingJob::dispatch($recording);

        Toast::flashSuccess(
            'Thumbnail regeneration started',
            'It is captured in the background; reload in a moment.',
        );

        return back();
    }

    public function bulkRegenerateThumbnails(Request $request): RedirectResponse
    {
        $this->authorize('create', Recording::class);

        $validated = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer'],
        ]);

        $recordings = Recording::whereIn('id', $validated['ids'])
            ->whereNotNull('m3u8_url')
            ->get();

        foreach ($recordings as $recording) {
            $recording->update(['thumbnail_path' => null, 'thumbnail_capture_error' => null]);
            ProcessRecordingJob::dispatch($recording);
        }

        Toast::flashSuccess(
            'Thumbnail regeneration started',
            $recordings->count().' queued.',
        );

        return back();
    }

    public function bulkDestroy(Request $request): RedirectResponse
    {
        $this->authorize('create', Recording::class);

        $validated = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer'],
        ]);

        $recordings = Recording::whereIn('id', $validated['ids'])->get();
        $recordings->each->delete();

        Toast::flashSuccess('Recordings deleted', $recordings->count().' removed.');

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Recording $recording): array
    {
        return [
            'thumbnail' => $recording->thumbnail_url,
            'title' => $recording->title,
            'slug' => $recording->slug,
            'show' => $recording->show?->title ?? '-',
            'date' => $recording->date?->format('M j, Y H:i'),
            'duration' => $recording->duration,
            'views' => $recording->views,
            'is_published' => $recording->is_published
                ? Status::make('Published', Status::OK)
                : Status::make('Draft', Status::IDLE),
            'access' => $recording->hasAccessRestriction()
                ? Status::make('Restricted', Status::WARN)
                : Status::make('Public', Status::OK),
        ];
    }

    /**
     * @return array<int, Action>
     */
    private function rowActions(Recording $recording): array
    {
        $actions = [
            Action::link('edit', 'Edit', route('manage.recordings.edit', $recording))->icon('pencil'),
        ];

        if (request()->user()->can('update', $recording)) {
            $actions[] = $this->deleteAction($recording);
        }

        return $actions;
    }

    /**
     * @return array<int, Action>
     */
    private function recordActions(Recording $recording): array
    {
        $user = request()->user();
        $actions = [];

        if ($user->can('update', $recording)) {
            $actions[] = Action::post(
                'regenerate_thumbnail',
                'Regenerate Thumbnail',
                route('manage.recordings.thumbnail', $recording),
            )
                ->icon('image')
                ->tone(Status::WARN)
                ->disabled($recording->m3u8_url ? null : 'There is no playlist to capture from.')
                ->confirm(
                    'Regenerate thumbnail',
                    'A new frame is captured from the video and replaces the current thumbnail.',
                    'Regenerate',
                );

            $actions[] = $this->deleteAction($recording);
        }

        return $actions;
    }

    private function deleteAction(Recording $recording): Action
    {
        return Action::delete('delete', 'Delete', route('manage.recordings.destroy', $recording))
            ->icon('trash-2')
            ->tone(Status::DANGER)
            ->confirm('Delete recording', "'{$recording->title}' will no longer be watchable.", 'Delete');
    }

    /**
     * @return array<int, Action>
     */
    private function bulkActions(): array
    {
        if (! request()->user()->can('create', Recording::class)) {
            return [];
        }

        return [
            Action::post('bulk_thumbnails', 'Regenerate Thumbnails', route('manage.recordings.bulk.thumbnail'))
                ->icon('image')
                ->tone(Status::WARN)
                ->confirm(
                    'Regenerate thumbnails',
                    'Recordings without a playlist are skipped.',
                    'Regenerate',
                ),
            Action::delete('bulk_delete', 'Delete', route('manage.recordings.bulk.destroy'))
                ->icon('trash-2')
                ->tone(Status::DANGER)
                ->confirm('Delete selected recordings', 'This cannot be undone.', 'Delete'),
        ];
    }

    /**
     * @return array<int, Action>
     */
    private function pageActions(): array
    {
        if (! request()->user()->can('create', Recording::class)) {
            return [];
        }

        return [
            Action::link('create', 'New Recording', route('manage.recordings.create'))->icon('plus'),
        ];
    }

    /**
     * Shows a recording can be attached to, newest first: a recording is almost
     * always of something that just ended.
     *
     * @return array<int, array{value: int|string, label: string}>
     */
    private function showOptions(): array
    {
        $options = [['value' => '', 'label' => 'Not linked to a show']];

        foreach (Show::with('source')->orderByDesc('scheduled_start')->limit(200)->get() as $show) {
            $options[] = [
                'value' => $show->id,
                'label' => $show->title.($show->source ? ' ('.$show->source->name.')' : ''),
            ];
        }

        return $options;
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private function roleOptions(): array
    {
        return Role::orderByDesc('priority')
            ->get()
            ->map(fn (Role $role) => ['value' => $role->slug, 'label' => $role->name])
            ->all();
    }
}
