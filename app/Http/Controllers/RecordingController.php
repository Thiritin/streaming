<?php

namespace App\Http\Controllers;

use App\Models\Recording;
use App\Models\RecordingProgress;
use App\Models\Show;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class RecordingController extends Controller
{
    /**
     * How many recordings Continue watching carries.
     */
    private const SHELF_SIZE = 12;

    /**
     * Page size for the filtered grid. Deliberately a multiple of the widest
     * column count so a full page never ends on a ragged row.
     */
    private const PAGE_SIZE = 24;

    /**
     * Archive landing page.
     *
     * One grid, always, newest first until asked otherwise, paged in as it is
     * scrolled. The archive is around twenty recordings a year, so a wall of
     * shelves would show most of the same recordings three times over; chips and
     * a sort narrow it in place instead.
     *
     * The one shelf left is Continue watching, and only on the unfiltered page:
     * once a viewer has said what they are after, the grid is the answer.
     */
    public function index(Request $request)
    {
        $user = Auth::user();

        $filters = $this->filters($request);

        return Inertia::render('Archive/Index', [
            'filters' => $filters,
            'chips' => $this->chips($user),
            'totalRecordings' => Recording::where('is_published', true)->accessibleBy($user)->count(),
            'continueWatching' => $this->isFiltered($filters)
                ? []
                : $this->continueWatching($user),
        ] + $this->gridProps($request, $user, $filters));
    }

    /**
     * One year's collection.
     *
     * The year chips on the index filter in place, so this route exists for links
     * that were already handed out. It answers the same grid, pinned to the year.
     */
    public function year(Request $request, int $year)
    {
        return redirect()->route('recordings.index', array_filter([
            'year' => $year,
            'search' => $request->get('search'),
            'sort' => $request->get('sort'),
        ]));
    }

    /**
     * Titles matching what has been typed so far, for the search dropdown.
     *
     * Deliberately not an Inertia page: it answers while the viewer is still
     * typing, and re-rendering the archive on every keystroke would be absurd.
     */
    public function suggest(Request $request)
    {
        $term = trim((string) $request->get('q', ''));

        if (mb_strlen($term) < 2) {
            return response()->json(['suggestions' => []]);
        }

        $user = Auth::user();

        $recordings = Recording::where('is_published', true)
            ->accessibleBy($user)
            ->where('title', 'like', '%'.$term.'%')
            ->with('source:id,name')
            /*
             * Newest first, views only to break a tie. A show that runs every year is
             * the ordinary case here, and the one somebody typing its name wants is
             * this year's - which is never the most watched, because the older cuts
             * have had a year longer to collect views.
             */
            ->orderByDesc('date')
            ->orderByDesc('views')
            ->limit(8)
            ->get();

        return response()->json([
            'suggestions' => $recordings->map(fn (Recording $recording) => [
                'id' => $recording->id,
                'title' => $recording->title,
                'year' => $recording->date?->year,
                'source_name' => $recording->source?->name,
                'thumbnail_url' => $recording->thumbnail_url,
                'url' => route('recordings.show', $recording->id),
            ])->values(),
        ]);
    }

    /**
     * The filter set, normalised. Anything unrecognised is dropped rather than
     * passed through, so a hand-typed sort cannot reach the query builder.
     */
    private function filters(Request $request): array
    {
        $sort = $request->get('sort');

        return [
            'search' => $request->filled('search') ? trim((string) $request->get('search')) : null,
            /*
             * The archive is filed by run of the convention, not by calendar year. The
             * year filter is still honoured for links handed out before the calendar
             * existed and for the /archive/year/{year} redirect; it just no longer has
             * a chip of its own unless something is filed under no run at all.
             */
            'event' => $request->filled('event') ? (string) $request->get('event') : null,
            'year' => $request->filled('year') ? (int) $request->get('year') : null,
            'source' => $request->filled('source') ? (string) $request->get('source') : null,
            'category' => $request->filled('category') ? (string) $request->get('category') : null,
            'sort' => in_array($sort, ['newest', 'oldest', 'views', 'longest'], true) ? $sort : 'newest',
        ];
    }

    private function isFiltered(array $filters): bool
    {
        return $filters['search'] !== null
            || $filters['event'] !== null
            || $filters['year'] !== null
            || $filters['source'] !== null
            || $filters['category'] !== null
            || $filters['sort'] !== 'newest';
    }

    /**
     * The chip bar: every year and every source that actually has something behind
     * it, so a chip never leads to an empty grid.
     */
    private function chips($user): array
    {
        $recordings = Recording::where('is_published', true)
            ->accessibleBy($user)
            ->with([
                'source:id,name,slug',
                'category:id,name,slug,sort_order',
                'event:id,name,slug,starts_on',
                'show:id,category_id,event_id',
                'show.category:id,name,slug,sort_order',
                'show.event:id,name,slug,starts_on',
            ])
            ->get(['id', 'date', 'source_id', 'category_id', 'event_id', 'show_id']);

        $collections = $this->collectionChips($recordings);

        $sources = $recordings
            ->filter(fn (Recording $recording) => $recording->source !== null)
            ->groupBy(fn (Recording $recording) => $recording->source->slug)
            ->map(fn ($group, $slug) => [
                'slug' => $slug,
                'name' => $group->first()->source->name,
                'count' => $group->count(),
            ])
            ->sortByDesc('count')
            ->values();

        // A category chip counts the recordings that have it through their show as
        // well as the ones labelled directly, because that is what the chip filters.
        $categories = $recordings
            ->map(fn (Recording $recording) => $recording->effectiveCategory())
            ->filter()
            ->groupBy('slug')
            ->map(fn ($group, $slug) => [
                'slug' => $slug,
                'name' => $group->first()->name,
                'sort_order' => $group->first()->sort_order,
                'count' => $group->count(),
            ])
            ->sortBy([['sort_order', 'asc'], ['name', 'asc']])
            ->values();

        return [
            'collections' => $collections,
            'sources' => $sources,
            'categories' => $categories,
        ];
    }

    /**
     * The first chip row: one chip per run of the convention, newest first.
     *
     * A recording filed under no run - published before the calendar was set up, or
     * a one-off that belongs to no run - still needs somewhere to be, so its year
     * gets a chip of its own after the events. That is the only place a year chip
     * survives, and it disappears the moment everything is filed.
     *
     * @param  Collection<int, Recording>  $recordings
     * @return array<int, array<string, mixed>>
     */
    private function collectionChips(Collection $recordings): array
    {
        [$filed, $unfiled] = $recordings->partition(
            fn (Recording $recording) => $recording->effectiveEvent() !== null,
        );

        $events = $filed
            ->groupBy(fn (Recording $recording) => $recording->effectiveEvent()->slug)
            ->map(function (Collection $group) {
                $event = $group->first()->effectiveEvent();

                return [
                    'key' => 'event:'.$event->slug,
                    'label' => $event->name,
                    'count' => $group->count(),
                    'event' => $event->slug,
                    'year' => null,
                    'sort' => $event->starts_on?->timestamp ?? 0,
                ];
            })
            ->sortByDesc('sort')
            ->values();

        $years = $unfiled
            ->map(fn (Recording $recording) => $recording->date?->year)
            ->filter()
            ->countBy()
            ->map(fn (int $count, $year) => [
                'key' => 'year:'.$year,
                'label' => (string) $year,
                'count' => $count,
                'event' => null,
                'year' => (int) $year,
                'sort' => (int) $year,
            ])
            ->sortByDesc('sort')
            ->values();

        return $events->concat($years)
            ->map(fn (array $chip) => Arr::except($chip, 'sort'))
            ->values()
            ->all();
    }

    /**
     * The filtered grid, paginated so the page does not load the whole archive to
     * show the first two rows. Later pages arrive as merged Inertia props.
     */
    private function gridProps(Request $request, $user, array $filters): array
    {
        $query = $this->publishedRecordings($user, $filters['search'])
            ->with(['source:id,name,slug', 'category:id,name,slug', 'event:id,name,slug', 'show:id,category_id,event_id', 'show.category:id,name,slug', 'show.event:id,name,slug']);

        if ($filters['event']) {
            $query->inEvent($filters['event']);
        }

        if ($filters['year']) {
            $query->whereYear('date', $filters['year']);
        }

        if ($filters['source']) {
            $query->whereHas('source', fn ($q) => $q->where('slug', $filters['source']));
        }

        if ($filters['category']) {
            $query->inCategory($filters['category']);
        }

        match ($filters['sort']) {
            'oldest' => $query->orderBy('date'),
            'views' => $query->orderByDesc('views')->orderByDesc('date'),
            'longest' => $query->orderByDesc('duration')->orderByDesc('date'),
            default => $query->orderByDesc('date'),
        };

        $page = $query->paginate(self::PAGE_SIZE)->withQueryString();
        $progress = $this->progressFor($user, collect($page->items())->pluck('id'));

        return [
            'recordings' => Inertia::merge(
                collect($page->items())
                    ->map(fn (Recording $recording) => $this->tile($recording, $progress))
                    ->values()
                    ->all()
            ),
            'pagination' => [
                'page' => $page->currentPage(),
                'lastPage' => $page->lastPage(),
                'total' => $page->total(),
            ],
            /*
             * Page one only, and only while the grid is newest-first: prepending
             * them to every page would repeat the same processing tiles all the
             * way down, and prepending them to a most-viewed grid would put four
             * tiles with no views at the top of it.
             */
            'pending' => $page->currentPage() === 1 && $filters['sort'] === 'newest'
                ? $this->pendingTiles($filters)
                : [],
        ];
    }

    /**
     * Started but not finished, most recently touched first. Signed-in only:
     * there is no row to read for a guest.
     */
    private function continueWatching($user): Collection
    {
        if (! $user) {
            return collect();
        }

        $rows = RecordingProgress::where('user_id', $user->id)
            ->where('completed', false)
            ->with(['recording.source:id,name,slug', 'recording.category:id,name,slug', 'recording.show:id,category_id', 'recording.show.category:id,name,slug'])
            ->orderByDesc('updated_at')
            ->limit(self::SHELF_SIZE * 2)
            ->get()
            ->filter(function (RecordingProgress $row) use ($user) {
                $recording = $row->recording;

                if (! $recording || ! $recording->is_published || ! $recording->canBeAccessedBy($user)) {
                    return false;
                }

                $fraction = $row->fraction();

                return $fraction >= RecordingProgress::STARTED_AT
                    && $fraction < RecordingProgress::COMPLETE_AT;
            })
            ->take(self::SHELF_SIZE);

        if ($rows->isEmpty()) {
            return collect();
        }

        $progress = $this->progressFor($user, $rows->pluck('recording_id'));

        return $rows
            ->map(fn (RecordingProgress $row) => $this->tile($row->recording, $progress))
            ->values();
    }

    /**
     * Playback positions keyed by recording id, for the bar across the tile.
     */
    private function progressFor($user, Collection $recordingIds): array
    {
        if (! $user || $recordingIds->isEmpty()) {
            return [];
        }

        return RecordingProgress::where('user_id', $user->id)
            ->whereIn('recording_id', $recordingIds)
            ->get()
            /*
             * The same window Continue watching uses. Thirty seconds into a
             * two-hour recording is not a resume point, and a tile that says
             * "1h left" for it while the shelf above has nothing on it is the
             * page disagreeing with itself.
             */
            ->filter(function (RecordingProgress $row) {
                $fraction = $row->fraction();

                return $fraction >= RecordingProgress::STARTED_AT
                    && $fraction < RecordingProgress::COMPLETE_AT;
            })
            ->mapWithKeys(fn (RecordingProgress $row) => [
                $row->recording_id => [
                    'position' => $row->position,
                    'fraction' => round($row->fraction(), 4),
                    'completed' => $row->completed,
                    // The length the position was measured against, which is what a
                    // tile has to count "left" from. Reading that off the recording
                    // instead is how "23 min left" appeared on something already
                    // watched to the end.
                    'duration' => $row->duration ?: $row->recording?->duration,
                    // When this was last written, so a page restored from the
                    // client's history cache can tell whether what it remembers
                    // from the player is newer than what the server just sent.
                    'updated_at' => $row->updated_at?->getTimestamp(),
                ],
            ])
            ->all();
    }

    /**
     * What a tile needs and nothing else. Shaped here rather than serialised off
     * the model, so a grid of 24 does not carry cut markers and archive prefixes.
     */
    private function tile(Recording $recording, array $progress = []): array
    {
        return [
            'id' => $recording->id,
            'title' => $recording->title,
            'date' => $recording->date?->toJSON(),
            'duration' => $recording->duration,
            'views' => (int) $recording->views,
            'thumbnail_url' => $recording->thumbnail_url,
            'source_name' => $recording->source?->name,
            'source_slug' => $recording->source?->slug,
            'category_name' => $recording->effectiveCategory()?->name,
            // The same playlist the player uses. The hover preview attaches to it
            // at the lowest rendition; nothing else is stored per recording to
            // preview from. A recording registered from outside has no archive to
            // render a playlist from - that route answers 410 for one - so it
            // previews against the URL it carries, which is what its player loads.
            'preview_url' => $recording->hasCut()
                ? route('recordings.playlist.master', $recording->slug)
                : $recording->m3u8_url,
            'progress' => $progress[$recording->id] ?? null,
            'is_pending' => false,
        ];
    }

    private function publishedRecordings($user, ?string $search)
    {
        $query = Recording::where('is_published', true)->accessibleBy($user);

        if ($search) {
            $query->where(function ($query) use ($search) {
                $query->where('title', 'like', '%'.$search.'%')
                    ->orWhere('description', 'like', '%'.$search.'%');
            });
        }

        return $query;
    }

    private function pendingShows(?string $search)
    {
        // Shows the audience was promised would be available afterwards, but which have
        // not been published yet. `announce_recording` is the promise; it says nothing
        // about capture, which happens for every source unconditionally.
        $query = Show::where('announce_recording', true)
            ->where('status', 'ended')
            ->with(['source:id,name,slug', 'category:id,name,slug', 'event:id,name,slug'])
            ->whereDoesntHave('recordings', fn ($q) => $q->where('is_published', true));

        if ($search) {
            $query->where(function ($query) use ($search) {
                $query->where('title', 'like', '%'.$search.'%')
                    ->orWhere('description', 'like', '%'.$search.'%');
            });
        }

        return $query
            ->orderBy('actual_end', 'desc')
            ->orderBy('scheduled_end', 'desc')
            ->get();
    }

    /**
     * Pending shows shaped like recordings, so the same tile renders both.
     * `is_pending` is what the tile keys off to dim itself and drop its link.
     */
    private function pendingTiles(array $filters): Collection
    {
        return $this->pendingShows($filters['search'])
            ->filter(fn (Show $show) => $filters['event'] === null
                || $show->event?->slug === $filters['event'])
            ->filter(fn (Show $show) => $filters['year'] === null
                || ($show->actual_end ?? $show->scheduled_end)?->year === $filters['year'])
            ->filter(fn (Show $show) => $filters['source'] === null || $show->source?->slug === $filters['source'])
            ->filter(fn (Show $show) => $filters['category'] === null || $show->category?->slug === $filters['category'])
            // Dates go out as ISO strings, matching how the models serialise theirs, so
            // the merged list sorts on one comparable type.
            ->map(fn (Show $show) => [
                'id' => 'pending-'.$show->id,
                'title' => $show->title,
                'date' => ($show->actual_end ?? $show->scheduled_end)?->toJSON(),
                'thumbnail_url' => null,
                'duration' => null,
                'views' => 0,
                'source_name' => $show->source?->name,
                'source_slug' => $show->source?->slug,
                'category_name' => $show->category?->name,
                'preview_url' => null,
                'progress' => null,
                'is_pending' => true,
            ])
            ->values();
    }

    public function show(Recording $recording)
    {
        $user = Auth::user();

        if (! $recording->is_published) {
            abort(404);
        }

        // Check access restrictions
        if (! $recording->canBeAccessedBy($user)) {
            return redirect()->route('recordings.index')
                ->with('error', 'You do not have permission to view this recording');
        }

        // Increment views
        $recording->increment('views');

        $recording->load(['source:id,name,slug', 'category:id,name,slug', 'show:id,category_id', 'show.category:id,name,slug']);

        $upNext = $this->upNext($recording, $user);

        return Inertia::render('RecordingPlayer', [
            'recording' => $recording,
            'sourceName' => $recording->source?->name,
            // Stretches the player may offer a way past. Sorted and non-overlapping,
            // so the player can walk them without deciding anything.
            'skips' => $recording->skips(),
            'category' => $recording->effectiveCategory()?->only(['name', 'slug']),
            'upNext' => $upNext,
            // Where this viewer left off, so the player can offer to resume. Zero
            // for a guest, and zero once they have watched it to the end.
            'resumeAt' => $this->resumeAt($recording, $user),
        ]);
    }

    /**
     * What plays after this one: the rest of the same source first, newest first,
     * then the same year to fill the rail. Same source before same year, because
     * a stage's own programme is the closest thing the archive has to a channel.
     */
    private function upNext(Recording $recording, $user): Collection
    {
        $take = 15;

        $sameSource = $recording->source_id
            ? $this->publishedRecordings($user, null)
                ->with(['source:id,name,slug', 'category:id,name,slug', 'show:id,category_id', 'show.category:id,name,slug'])
                ->where('id', '!=', $recording->id)
                ->where('source_id', $recording->source_id)
                ->orderByDesc('date')
                ->limit($take)
                ->get()
            : collect();

        $fill = collect();

        if ($sameSource->count() < $take && $recording->date) {
            $fill = $this->publishedRecordings($user, null)
                ->with(['source:id,name,slug', 'category:id,name,slug', 'show:id,category_id', 'show.category:id,name,slug'])
                ->where('id', '!=', $recording->id)
                ->whereNotIn('id', $sameSource->pluck('id'))
                ->whereYear('date', $recording->date->year)
                ->orderByDesc('views')
                ->limit($take - $sameSource->count())
                ->get();
        }

        $all = $sameSource->concat($fill);
        $progress = $this->progressFor($user, $all->pluck('id'));

        return $all->map(fn (Recording $item) => $this->tile($item, $progress))->values();
    }

    private function resumeAt(Recording $recording, $user): int
    {
        if (! $user) {
            return 0;
        }

        $row = RecordingProgress::where('user_id', $user->id)
            ->where('recording_id', $recording->id)
            ->first();

        if (! $row || $row->completed) {
            return 0;
        }

        $fraction = $row->fraction();

        if ($fraction < RecordingProgress::STARTED_AT || $fraction >= RecordingProgress::COMPLETE_AT) {
            return 0;
        }

        return $row->position;
    }
}
