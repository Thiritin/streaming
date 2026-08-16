<?php

namespace App\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Http\Requests\Manage\RecordingRequest;
use App\Jobs\ProcessRecordingJob;
use App\Models\Recording;
use App\Models\Role;
use App\Models\Show;
use App\Services\ArchivePlaylistService;
use App\Support\Manage\Action;
use App\Support\Manage\Column;
use App\Support\Manage\Filter;
use App\Support\Manage\Status;
use App\Support\Manage\Table;
use App\Support\Manage\Toast;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
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
                // A cut carries these; a recording registered from outside does not, and
                // the form uses their presence to decide which fields it owns.
                'starts_at' => $recording->starts_at?->toIso8601String(),
                'ends_at' => $recording->ends_at?->toIso8601String(),
                'status' => $recording->status,
                'build_error' => $recording->build_error,
                'segment_count' => $recording->segment_count,
                'playlist_built_at' => $recording->playlist_built_at?->diffForHumans(),
            ],
            // Bounds the scrubber. Without it a cut can run past the end of the archive
            // (segments not uploaded yet) or before its start (already expired), and the
            // resulting empty range reads as data loss rather than a range mistake.
            'available' => $this->archiveBounds($recording),
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

        // A cut is derived state: the archive is truth and the playlist is generated
        // from the markers, so every save rebuilds rather than mutating media. That is
        // what makes trimming repeatable and non-destructive, months after the fact.
        if ($recording->hasCut()) {
            if (! app(ArchivePlaylistService::class)->rebuild($recording->fresh())) {
                Toast::flashError('Playlist not built', $recording->fresh()->build_error);

                return back();
            }
        }

        Toast::flashSuccess('Recording updated');

        return back();
    }

    /**
     * Cut a draft recording from a show, copying its title and markers.
     *
     * Deliberately does not require the show to have ended. The archive is a continuous
     * per-source timeline, so a range can be cut, published and re-cut while the show is
     * still running; the main source stays online for the whole event and never produces
     * an end to wait for. What it does require is an explicit end marker, since there is
     * no natural one.
     */
    public function storeFromShow(Request $request, Show $show): RedirectResponse
    {
        $this->authorize('create', Recording::class);

        if (! $show->source) {
            Toast::flashError('No source', 'That show has no source, so there is no archive to cut from.');

            return back();
        }

        if (! $show->actual_start) {
            Toast::flashError('Not started', 'That show has not gone live yet, so there is nothing to cut.');

            return back();
        }

        $endsAt = $request->filled('ends_at')
            ? CarbonImmutable::parse($request->string('ends_at')->toString())
            : $show->actual_end;

        if (! $endsAt) {
            Toast::flashError(
                'End marker needed',
                'That show is still live. Set an end marker to cut a recording from it.'
            );

            return back();
        }

        $recording = Recording::create([
            'show_id' => $show->id,
            'source_id' => $show->source->id,
            'title' => $show->title,
            'slug' => $this->uniqueSlug($show->title),
            'description' => $show->description,
            'date' => $show->actual_start,
            'starts_at' => $show->actual_start,
            'ends_at' => $endsAt,
            'archive_prefix' => "archive/{$show->source->slug}",
            'status' => 'draft',
            'is_published' => false,
        ]);

        // Building reads a couple of hour indexes and writes four playlists, so it runs
        // inline and the operator lands on a finished recording rather than a spinner.
        $built = app(ArchivePlaylistService::class)->rebuild($recording);

        if (! $built) {
            Toast::flashError('Draft created, playlist not built', $recording->fresh()->build_error);
        } else {
            Toast::flashSuccess('Recording drafted', "Cut from '{$show->title}'. Adjust the markers before publishing.");
        }

        return to_route('manage.recordings.edit', $recording);
    }

    /**
     * Rebuild without changing the markers.
     *
     * Useful when the archive has caught up since the last build: the uploader runs a few
     * seconds behind live, so a cut whose end was at the live edge will have been short
     * by a segment or two.
     */
    public function rebuild(Recording $recording): RedirectResponse
    {
        $this->authorize('update', $recording);

        if (! $recording->hasCut()) {
            Toast::flashError('Nothing to rebuild', 'This recording has no cut markers.');

            return back();
        }

        if (! app(ArchivePlaylistService::class)->rebuild($recording)) {
            Toast::flashError('Playlist not built', $recording->fresh()->build_error);

            return back();
        }

        $fresh = $recording->fresh();
        Toast::flashSuccess('Playlist rebuilt', "{$fresh->segment_count} segments, {$fresh->formatted_duration}.");

        return back();
    }

    /**
     * Playlist for an arbitrary window of the source archive, for the trim editor.
     *
     * Separate from the recording's own playlist because the editor has to show material
     * outside the current markers; that is how an operator finds where the show actually
     * starts. The window is bounded so a careless request cannot ask for seven days of a
     * continuously running source in one playlist.
     */
    public function preview(Request $request, Recording $recording)
    {
        $this->authorize('view', $recording);

        $source = $recording->archiveSourceSlug();
        abort_unless($source, 404);

        $rendition = $request->string('rendition', 'hd')->toString();
        $service = app(ArchivePlaylistService::class);

        abort_unless(in_array($rendition, $service->renditions(), true), 404);

        $validated = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date'],
        ]);

        $from = CarbonImmutable::parse($validated['from']);
        $to = CarbonImmutable::parse($validated['to']);

        // Carbon 3's diffInHours is signed, so `$to->diffInHours($from)` on a
        // forward range is negative and the guard never fired: a single request
        // could ask for the whole con as one playlist. Compare in the direction the
        // range actually runs, and reject a reversed one outright rather than
        // silently rendering nothing.
        abort_if($to <= $from, 422, 'The preview range ends before it starts.');

        if ($from->diffInHours($to) > 4) {
            $to = $from->addHours(4);
        }

        try {
            $body = $service->renderRange($source, $from, $to, $rendition);
        } catch (\Throwable $e) {
            abort(410, $e->getMessage());
        }

        return response($body, 200, [
            'Content-Type' => 'application/vnd.apple.mpegurl',
            // Segment URLs inside are signed and time limited.
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * How much of the source's archive is still cuttable.
     *
     * Bounded at both ends for different reasons: the uploader runs a few seconds behind
     * live, and old hours eventually expire out of the archive.
     */
    protected function archiveBounds(Recording $recording): array
    {
        $source = $recording->archiveSourceSlug();

        if (! $source) {
            return ['from' => null, 'to' => null];
        }

        $range = app(ArchivePlaylistService::class)->availableRange($source);

        return [
            'from' => $range['from']?->toIso8601String(),
            'to' => $range['to']?->toIso8601String(),
        ];
    }

    protected function uniqueSlug(string $title): string
    {
        $base = Str::slug($title);
        $slug = $base;
        $i = 1;

        while (Recording::where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
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
