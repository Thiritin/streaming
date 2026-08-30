<?php

namespace App\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Http\Requests\Manage\RecordingRequest;
use App\Jobs\ProcessRecordingJob;
use App\Jobs\ScanArchiveStorageJob;
use App\Models\Category;
use App\Models\Event;
use App\Models\Recording;
use App\Models\Role;
use App\Models\Show;
use App\Services\ArchivePlaylistService;
use App\Services\ArchiveStorageService;
use App\Support\EventFilter;
use App\Support\Manage\Action;
use App\Support\Manage\Column;
use App\Support\Manage\Filter;
use App\Support\Manage\Status;
use App\Support\Manage\Table;
use App\Support\Manage\Toast;
use App\Support\SkipSegments;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Inertia\Inertia;
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

        $table = Table::make(Recording::query()->with(['show.category', 'show.event', 'category', 'event']))
            ->name('recordings')
            ->columns([
                Column::image('thumbnail', 'Thumbnail'),
                Column::text('title', 'Title')->searchable()->sortable(),
                Column::copyable('slug', 'Slug')->searchable()->toggleable(hiddenByDefault: true),
                Column::text('show', 'Show')->toggleable(),
                Column::badge('category', 'Category')->toggleable(),
                Column::badge('event', 'Event')->toggleable(),
                Column::datetime('date', 'Date')->sortable(),
                Column::duration('duration', 'Duration'),
                Column::number('size', 'Size')->sortable('archive_bytes'),
                Column::number('views', 'Views')->sortable(),
                Column::badge('is_published', 'Published'),
                Column::badge('access', 'Access')->toggleable(hiddenByDefault: true),
            ])
            ->filters([
                Filter::ternary('is_published', 'Published')
                    ->trueLabel('Published only')
                    ->falseLabel('Unpublished only')
                    ->placeholder('All recordings'),
                Filter::select('category', 'Category')
                    ->options(Category::ordered()->pluck('name', 'slug')->all())
                    ->apply(fn ($query, string $value) => $query->inCategory($value)),
                /*
                 * Same default as the Shows list: the run that is on, or the one that
                 * just finished. `none` is the archive that predates the calendar -
                 * cuts filed under no run at all, which nothing else surfaces.
                 */
                Filter::select('event', 'Event')
                    ->options(EventFilter::options())
                    ->default(EventFilter::default())
                    ->placeholder('All events')
                    ->apply(fn ($query, string $value) => $value === EventFilter::NONE
                        ? $query->withoutEvent()
                        : $query->inEvent($value)),
            ])
            ->defaultSort('date', 'desc')
            ->rows(fn (Recording $recording) => $this->row($recording))
            ->recordUrl(fn (Recording $recording) => route('manage.recordings.edit', $recording))
            ->rowActions(fn (Recording $recording) => $this->rowActions($recording))
            ->bulkActions($this->bulkActions())
            ->pageActions($this->pageActions());

        return inertia('Manage/Recordings/Index', [
            'table' => $table->toArray($request),
            // Deferred: the totals come out of the cache, but the panel is the one part
            // of this page nobody is waiting on, and keeping it off the initial payload
            // means a table sort never re-serialises it.
            'storage' => Inertia::defer(fn () => $this->storagePanel(app(ArchiveStorageService::class))),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Recording::class);

        return inertia('Manage/Recordings/Form', [
            'recording' => null,
            'options' => [
                'shows' => $this->showOptions(),
                'categories' => $this->categoryOptions(),
                'events' => $this->eventOptions(),
                'roles' => $this->roleOptions(),
            ],
            'defaults' => [
                'show_id' => '',
                'category_id' => '',
                'event_id' => '',
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
                'category_id' => $recording->category_id,
                'event_id' => $recording->event_id,
                'inherited_event' => $recording->show?->event?->name,
                // What applies when the override above is empty, so the form can say
                // what the recording is currently filed as without pretending it is set.
                'inherited_category' => $recording->show?->category?->name,
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
                'skip_segments' => $recording->skips(),
                // What those skips were marked against; handed back on save so a
                // cut changed underneath this form is caught rather than written on.
                'cut_fingerprint' => $recording->cutFingerprint(),
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
                'categories' => $this->categoryOptions(),
                'events' => $this->eventOptions(),
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

        /*
         * Refuse a save built against a cut that has since moved.
         *
         * Two people work a recording at once - one trimming, one marking skips -
         * and the skips in this payload were marked against the media the form
         * loaded. If the in-point has changed since, every one of them means a
         * different moment now, and writing them would put the buttons minutes
         * away from the intermissions they belong to.
         */
        if ($request->filled('cut_fingerprint')
            && $request->input('cut_fingerprint') !== $recording->cutFingerprint()
            && $this->skipsChanged($request, $recording)
        ) {
            Toast::flashDanger(
                'The cut changed while you were editing',
                'Somebody has re-cut this recording, so the skip points you marked no longer line up. Reload and mark them against the new cut.',
            );

            return back();
        }

        $data = $request->validated();

        /*
         * A trim of the head moves every skip with it. They are seconds from the
         * start of the recording, so an in-point pushed thirty seconds later means
         * each of them is thirty seconds earlier than it was - the alternative is
         * an operator silently losing the alignment of work they had already done.
         */
        $shift = $this->startShift($recording, $data);

        if ($shift !== 0 && array_key_exists('skip_segments', $data)) {
            $data['skip_segments'] = SkipSegments::shift(
                $data['skip_segments'],
                -$shift,
                $this->cutLength($data) ?: $recording->duration,
            );
        }

        $recording->update($data);

        // A cut is derived state: the archive is truth and the playlist is generated
        // from the markers, so every save rebuilds rather than mutating media. That is
        // what makes trimming repeatable and non-destructive, months after the fact.
        if ($recording->hasCut()) {
            if (! app(ArchivePlaylistService::class)->rebuild($recording->fresh())) {
                Toast::flashDanger('Playlist not built', $recording->fresh()->build_error);

                return back();
            }
        }

        Toast::flashSuccess('Recording updated');

        return back();
    }

    /**
     * Whether this save carries different skip points than the record holds. A
     * stale fingerprint only matters when it does: a save that leaves the skips
     * alone - ticking Published, fixing a title - has nothing to misalign, and
     * refusing it because a job filled the duration in behind the form is a wall
     * with nothing behind it.
     */
    private function skipsChanged(RecordingRequest $request, Recording $recording): bool
    {
        if (! $request->has('skip_segments')) {
            return false;
        }

        return SkipSegments::normalise($request->input('skip_segments'), $recording->duration)
            !== $recording->skips();
    }

    /**
     * How far the in-point has moved in this save, in seconds. Positive means the
     * recording now starts later than it did.
     *
     * @param  array<string, mixed>  $data
     */
    private function startShift(Recording $recording, array $data): int
    {
        if (! $recording->starts_at || empty($data['starts_at'])) {
            return 0;
        }

        return (int) round(CarbonImmutable::parse($data['starts_at'])->getTimestamp() - $recording->starts_at->getTimestamp());
    }

    /**
     * The length the new markers describe, so a shifted skip is clamped to the cut
     * this save produces rather than to the one it replaces.
     *
     * @param  array<string, mixed>  $data
     */
    private function cutLength(array $data): ?int
    {
        if (empty($data['starts_at']) || empty($data['ends_at'])) {
            return null;
        }

        return max(0, (int) round(
            CarbonImmutable::parse($data['ends_at'])->getTimestamp() - CarbonImmutable::parse($data['starts_at'])->getTimestamp()
        ));
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
            Toast::flashDanger('No source', 'That show has no source, so there is no archive to cut from.');

            return back();
        }

        if (! $show->actual_start) {
            Toast::flashDanger('Not started', 'That show has not gone live yet, so there is nothing to cut.');

            return back();
        }

        $endsAt = $request->filled('ends_at')
            ? CarbonImmutable::parse($request->string('ends_at')->toString())
            : $show->actual_end;

        if (! $endsAt) {
            Toast::flashDanger(
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
            Toast::flashDanger('Draft created, playlist not built', $recording->fresh()->build_error);
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
            Toast::flashDanger('Nothing to rebuild', 'This recording has no cut markers.');

            return back();
        }

        if (! app(ArchivePlaylistService::class)->rebuild($recording)) {
            Toast::flashDanger('Playlist not built', $recording->fresh()->build_error);

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

        // A playlist names every segment in its range, so its size is linear in the span
        // asked for and an unbounded request is a denial of service against this endpoint.
        // The cap stays, but it is reported: truncating in silence is what made the cut
        // editor unusable past the first four hours. It requested the whole archive as one
        // window, got a quarter of it with a 200, and had no way to know - so seeking past
        // the truncation point moved the playhead to an instant the media did not contain,
        // which reads as a recording that stops early rather than a range that was clipped.
        $requestedTo = $to;

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
            // What was actually served, which is not always what was asked for.
            'X-Preview-From' => $from->toIso8601String(),
            'X-Preview-To' => $to->toIso8601String(),
            'X-Preview-Truncated' => $to->lt($requestedTo) ? 'true' : 'false',
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
     * Kick off a fresh bucket scan.
     *
     * Queued rather than run inline: it is a full listing of a bucket holding hundreds
     * of thousands of segments, so an inline version would hold the request open for
     * minutes and time out behind any sane proxy.
     */
    public function rescanStorage(): RedirectResponse
    {
        $this->authorize('create', Recording::class);

        ScanArchiveStorageJob::dispatch();

        Toast::flashSuccess(
            'Storage scan queued',
            'Listing the whole bucket takes a few minutes. Reload once it has run.',
        );

        return back();
    }

    /**
     * What the storage panel above the table shows.
     *
     * @return array<string, mixed>
     */
    private function storagePanel(ArchiveStorageService $storage): array
    {
        $usage = $storage->usage();

        return [
            'configured' => $usage['configured'],
            'error' => $usage['error'],
            'partial' => $usage['partial'],
            'scannedAt' => $usage['scanned_at'],
            'used' => $usage['bytes'] === null ? null : Number::fileSize($usage['bytes'], 1),
            'usedBytes' => $usage['bytes'],
            'free' => $usage['free'] === null ? null : Number::fileSize($usage['free'], 1),
            'quota' => $usage['quota'] === null ? null : Number::fileSize($usage['quota'], 1),
            'percent' => $usage['percent'],
            'objects' => $usage['objects'],
            'prefixes' => array_map(fn (array $prefix) => [
                'label' => $prefix['label'],
                'size' => Number::fileSize($prefix['bytes'], 1),
                'objects' => $prefix['objects'],
                // Share of the measured total, so the bar reads the same whether or not
                // a quota is configured.
                'share' => $usage['bytes'] > 0 ? round($prefix['bytes'] / $usage['bytes'] * 100, 1) : 0,
            ], array_slice($usage['prefixes'], 0, 8)),
        ];
    }

    /**
     * How much archive the cut spans.
     *
     * Not the same thing as what it costs: the segments are shared with every other cut
     * over the same source and with the live archive itself, so deleting the recording
     * reclaims none of it. The description says so, because a size column next to a
     * delete button invites exactly the wrong conclusion.
     *
     * @return array{display: string, description: string|null}|null
     */
    private function sizeCell(Recording $recording): ?array
    {
        if ($recording->archive_bytes === null) {
            return null;
        }

        return [
            'display' => '~'.Number::fileSize($recording->archive_bytes, 1),
            'description' => 'shared archive',
        ];
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
            'category' => $this->categoryCell($recording),
            'event' => $this->eventCell($recording),
            'date' => $recording->date?->format('M j, Y H:i'),
            'duration' => $recording->duration,
            'size' => $this->sizeCell($recording),
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
     * Category options for the form and the bulk modal. The empty entry means
     * "follow the show", which is the normal state for a recording.
     */
    private function categoryOptions(): array
    {
        return array_merge(
            [['value' => '', 'label' => 'Follow the show']],
            Category::ordered()
                ->get(['id', 'name'])
                ->map(fn (Category $category) => ['value' => $category->id, 'label' => $category->name])
                ->all(),
        );
    }

    /**
     * Event options for the form and the bulk modal. The empty entry means "follow
     * the show", which is the normal state for a recording cut from one.
     */
    private function eventOptions(): array
    {
        return array_merge(
            [['value' => '', 'label' => 'Follow the show']],
            Event::ordered()
                ->get(['id', 'name'])
                ->map(fn (Event $event) => ['value' => $event->id, 'label' => $event->name])
                ->all(),
        );
    }

    /**
     * The run as it reads on the row: its own, or the show's, said so.
     *
     * @return array{label: string, tone: string, icon: string|null}|null
     */
    private function eventCell(Recording $recording): ?array
    {
        if ($recording->event) {
            return Status::make($recording->event->name, Status::INFO);
        }

        return $recording->show?->event
            ? Status::make($recording->show->event->name.' (show)', Status::IDLE)
            : null;
    }

    /**
     * The category as it reads on the row: its own, or the show's, said so.
     *
     * @return array{label: string, tone: string, icon: string|null}|null
     */
    private function categoryCell(Recording $recording): ?array
    {
        if ($recording->category) {
            return Status::make($recording->category->name, Status::INFO);
        }

        return $recording->show?->category
            ? Status::make($recording->show->category->name.' (show)', Status::IDLE)
            : null;
    }

    /**
     * Override the category on a batch of recordings, or clear the override so
     * they follow their shows again.
     */
    public function bulkCategory(Request $request): RedirectResponse
    {
        $this->authorize('create', Recording::class);

        $validated = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
        ]);

        $categoryId = $validated['category_id'] ?? null;
        $count = Recording::whereIn('id', $validated['ids'])->update(['category_id' => $categoryId]);

        Toast::flashSuccess(
            'Category set',
            $categoryId
                ? $count.' recording(s) are now '.Category::find($categoryId)?->name.'.'
                : $count.' recording(s) follow their show again.',
        );

        return back();
    }

    /**
     * Override the run on a batch of recordings, or clear the override so they
     * follow their shows again. This is what files an archive that predates the
     * calendar under the runs it belongs to.
     */
    public function bulkEvent(Request $request): RedirectResponse
    {
        $this->authorize('create', Recording::class);

        $validated = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer'],
            'event_id' => ['nullable', 'integer', 'exists:events,id'],
        ]);

        $eventId = $validated['event_id'] ?? null;
        $count = Recording::whereIn('id', $validated['ids'])->update(['event_id' => $eventId]);

        Toast::flashSuccess(
            'Event set',
            $eventId
                ? $count.' recording(s) are now part of '.Event::find($eventId)?->name.'.'
                : $count.' recording(s) follow their show again.',
        );

        return back();
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
            Action::post('bulk_category', 'Set Category', route('manage.recordings.bulk.category'))
                ->icon('tags')
                ->tone(Status::IDLE)
                ->confirm(
                    'Set category on selected recordings',
                    'This overrides whatever their shows say. Clearing it hands them back to their show.',
                    'Set category',
                )
                ->fields([[
                    'key' => 'category_id',
                    'label' => 'Category',
                    'type' => 'select',
                    'required' => false,
                    'helper' => 'Leave empty to clear the override and follow the show again.',
                    'options' => Category::ordered()
                        ->get()
                        ->map(fn (Category $category) => ['value' => (string) $category->id, 'label' => $category->name])
                        ->all(),
                ]]),
            Action::post('bulk_event', 'Set Event', route('manage.recordings.bulk.event'))
                ->icon('calendar')
                ->tone(Status::IDLE)
                ->confirm(
                    'Set event on selected recordings',
                    'This overrides whatever their shows say. Clearing it hands them back to their show.',
                    'Set event',
                )
                ->fields([[
                    'key' => 'event_id',
                    'label' => 'Event',
                    'type' => 'select',
                    'required' => false,
                    'helper' => 'Leave empty to clear the override and follow the show again.',
                    'options' => Event::ordered()
                        ->get()
                        ->map(fn (Event $event) => ['value' => (string) $event->id, 'label' => $event->name])
                        ->all(),
                ]]),
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
        // The plan is the other half of this page - what was promised against what
        // has been cut - so it sits beside New Recording rather than being reachable
        // only from the rail. Offered before the create check: reading the plan is
        // not creating anything, and a read-only operator is exactly who wants it.
        $actions = [
            Action::link('plan', 'Recording plan', route('manage.recordings.plan'))
                ->icon('clipboard-list'),
        ];

        if (! request()->user()->can('create', Recording::class)) {
            return $actions;
        }

        return [
            ...$actions,
            Action::link('create', 'New Recording', route('manage.recordings.create'))->icon('plus'),
            Action::post('rescan_storage', 'Rescan Storage', route('manage.recordings.storage.rescan'))
                ->icon('refresh-cw')
                ->tone(Status::IDLE)
                ->confirm(
                    'Rescan archive storage',
                    'The whole bucket is listed, which takes a few minutes and runs in the background.',
                    'Rescan',
                ),
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
                // Grouped by year, because the list spans every event the installation has
                // ever run and two of them will have a show called "Opening Ceremony".
                // Ordering is by scheduled start descending, so the years come out newest
                // first and a group is contiguous without sorting again here.
                'group' => $show->scheduled_start->format('Y'),
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
