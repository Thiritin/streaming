<?php

namespace App\Http\Controllers;

use App\Models\Recording;
use App\Models\RecordingProgress;
use App\Models\Show;
use App\Support\RecordingViews;
use App\Support\SkipSegments;
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
     * How many a category rail carries before the rest goes behind See all.
     *
     * Lower than the Continue watching shelf on purpose: that one is a list of
     * things the viewer already started, so its length is the whole answer. A
     * category rail is a sample of a set that has a page of its own, and a cap
     * so high it never overflows is a See all nobody is ever offered.
     */
    private const RAIL_SIZE = 8;

    /**
     * Page size for the filtered grid. Deliberately a multiple of the widest
     * column count so a full page never ends on a ragged row.
     */
    private const PAGE_SIZE = 24;

    /**
     * Archive landing page.
     *
     * Unfiltered it is a shelf per category, most watched category first: "what
     * kind of thing is this" is the question somebody arriving at an archive
     * actually has, and a flat newest-first grid answers it by making them read
     * every tile. Ask anything of it - a chip, a search, a sort - and it becomes
     * the grid, newest first until asked otherwise, paged in as it is scrolled.
     *
     * Continue watching leads either way, and only on the unfiltered page: once a
     * viewer has said what they are after, the grid is the answer.
     */
    public function index(Request $request)
    {
        $user = Auth::user();

        $filters = $this->filters($request);
        $shelved = ! $this->isFiltered($filters);

        return Inertia::render('Archive/Index', [
            'filters' => $filters,
            'chips' => $this->chips($user, $filters),
            'totalRecordings' => Recording::where('is_published', true)->accessibleBy($user)->count(),
            'continueWatching' => $shelved ? $this->continueWatching($user) : [],
            'shelves' => $shelved ? $this->categoryShelves($user) : [],
        ] + ($shelved ? $this->emptyGridProps() : $this->gridProps($request, $user, $filters)));
    }

    /**
     * The grid's props, switched off. The page still declares them, so the client
     * never has to ask whether it is on a shelved page before reading them.
     */
    private function emptyGridProps(): array
    {
        return [
            'recordings' => [],
            'pagination' => ['page' => 1, 'lastPage' => 1, 'total' => 0],
            'groups' => [],
            'pending' => [],
        ];
    }

    /**
     * One rail per category, most watched category first.
     *
     * The order is the category's mean views per recording, not its total: a
     * category that ran twelve panels would otherwise outrank one that ran two
     * packed theatre nights purely by having more rows to add up. The mean asks
     * what a recording of this kind is worth to the audience, which is the thing
     * being ranked.
     *
     * Recordings the audience was promised but which are not published yet ride
     * their own category's rail at the front, where the grid used to put them.
     * They have no views to their name, so they are kept out of the mean.
     * Anything filed under no category is one rail at the end, never ranked.
     */
    private function categoryShelves($user): array
    {
        $recordings = $this->publishedRecordings($user, null)
            ->with([
                'source:id,name,slug',
                'category:id,name,slug',
                'event:id,name,slug',
                'show:id,category_id,event_id',
                'show.category:id,name,slug',
                'show.event:id,name,slug',
            ])
            ->orderByDesc('date')
            ->get();

        $progress = $this->progressFor($user, $recordings->pluck('id'));

        $entries = $this->pendingShows(null)
            ->map(fn (Show $show) => [
                'category' => $show->category,
                'tile' => $this->pendingTile($show),
                'views' => null,
            ])
            ->concat($recordings->map(fn (Recording $recording) => [
                'category' => $recording->effectiveCategory(),
                'tile' => $this->tile($recording, $progress),
                'views' => (int) $recording->views,
            ]));

        return $entries
            ->groupBy(fn (array $entry) => $entry['category']?->slug ?? '')
            ->map(function (Collection $group, string $slug) {
                $category = $group->first()['category'];
                $views = $group->pluck('views')->filter(fn ($value) => $value !== null);

                return [
                    'key' => $slug === '' ? 'uncategorised' : $slug,
                    'title' => $category?->name ?? 'Uncategorised',
                    // Where the rail runs out. Null for the uncategorised one:
                    // there is no chip that narrows to "no category".
                    'href' => $category ? route('recordings.index', ['category' => $slug]) : null,
                    'count' => $group->count(),
                    'recordings' => $group->take(self::RAIL_SIZE)->pluck('tile')->values()->all(),
                    // Uncategorised sorts below every real category, however well
                    // watched it happens to be.
                    'sort' => $category ? (float) ($views->avg() ?? 0) : -1.0,
                ];
            })
            ->sortByDesc('sort')
            ->map(fn (array $shelf) => Arr::except($shelf, 'sort'))
            ->values()
            ->all();
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
            'category' => $request->filled('category') ? (string) $request->get('category') : null,
            'sort' => in_array($sort, ['newest', 'oldest', 'views', 'longest'], true) ? $sort : 'newest',
        ];
    }

    private function isFiltered(array $filters): bool
    {
        return $filters['search'] !== null
            || $filters['event'] !== null
            || $filters['year'] !== null
            || $filters['category'] !== null
            || $filters['sort'] !== 'newest';
    }

    /**
     * The chip bar: the runs, and the categories inside whichever run is on screen.
     *
     * Categories are counted against the collection that is selected rather than
     * the whole archive, because a chip counted across every run reads "4 Theater"
     * and then hands back only the ones filed under this one.
     */
    private function chips($user, array $filters): array
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

        // A category chip counts the recordings that have it through their show as
        // well as the ones labelled directly, because that is what the chip filters.
        $categories = $this->inCollection($recordings, $filters)
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
            'categories' => $categories,
        ];
    }

    /**
     * The slice of the archive the collection chips point at. Events and years are
     * one axis, so at most one of them is ever on.
     *
     * @param  Collection<int, Recording>  $recordings
     * @return Collection<int, Recording>
     */
    private function inCollection(Collection $recordings, array $filters): Collection
    {
        if ($filters['event'] !== null) {
            return $recordings->filter(function (Recording $recording) use ($filters) {
                $event = $recording->effectiveEvent();

                return $event !== null
                    && ($event->slug === $filters['event'] || (string) $event->id === $filters['event']);
            });
        }

        if ($filters['year'] !== null) {
            return $recordings->filter(
                fn (Recording $recording) => $recording->date?->year === $filters['year'],
            );
        }

        return $recordings;
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

        if ($filters['category']) {
            $query->inCategory($filters['category']);
        }

        /*
         * A category on its own is read run by run: "what theatre is there" is
         * really "what theatre is there from each year", and a flat newest-first
         * grid buries this run's four under last run's nine. Picking a run already
         * answers the question, so grouping only applies while none is picked, and
         * only in the default order - a most-viewed grid sliced by run is two
         * orderings arguing.
         */
        $grouped = $this->isGrouped($filters);

        if ($grouped) {
            $query = $this->orderByEvent($query);
        }

        match ($filters['sort']) {
            'oldest' => $query->orderBy('date'),
            'views' => $query->orderByDesc('views')->orderByDesc('date'),
            'longest' => $query->orderByDesc('duration')->orderByDesc('date'),
            default => $query->orderByDesc('date'),
        };

        $page = $query->paginate(self::PAGE_SIZE)->withQueryString();
        $progress = $this->progressFor($user, collect($page->items())->pluck('id'));

        $tiles = collect($page->items())
            ->map(fn (Recording $recording) => $this->tile($recording, $progress))
            ->values()
            ->all();

        return [
            /*
             * A merge prop only from page two.
             *
             * Merging is what lets scrolling append the next page, but it is wrong
             * for the first one: a filter visit is a fresh list, and appending it to
             * whatever was on screen leaves the run just switched away from sitting
             * under the one that was asked for. The client used to say `reset` to
             * undo that, which turned the whole visit into a partial reload asking
             * for `recordings` alone - the chips, the filters and Continue watching
             * then kept the values they had, so a chip changed the URL and the grid
             * and nothing else. Deciding it here means a filter visit is an ordinary
             * visit that replaces every prop.
             */
            'recordings' => $page->currentPage() > 1
                ? Inertia::merge($tiles)
                : $tiles,
            'pagination' => [
                'page' => $page->currentPage(),
                'lastPage' => $page->lastPage(),
                'total' => $page->total(),
            ],
            /*
             * One entry per run present in the whole filtered set, not just the page
             * on screen: the heading over a section says how many there are, and a
             * count that grows as more of the grid is scrolled in would be a lie
             * every time it is read.
             */
            'groups' => $grouped ? $this->groupCounts($user, $filters) : [],
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
     * Whether the grid is read run by run: a category picked, no run picked, and
     * the default order.
     */
    private function isGrouped(array $filters): bool
    {
        return $filters['category'] !== null
            && $filters['event'] === null
            && $filters['year'] === null
            && $filters['sort'] === 'newest';
    }

    /**
     * Newest run first, with everything filed under no run at the end.
     *
     * A recording's run is its own or its show's, so the order has to come from a
     * coalesce over both - `orderBy('event_id')` would scatter every recording that
     * has its run through its show. Written as a correlated subquery rather than a
     * join: joining `shows` puts a second `is_published`, `category_id` and
     * `required_roles` in scope and every unqualified column in the filters becomes
     * ambiguous. Spelled out rather than using NULLS LAST, which Postgres has and
     * MySQL does not.
     */
    private function orderByEvent($query)
    {
        $runStart = '(select coalesce(own_event.starts_on, show_event.starts_on)
            from recordings as ordered
            left join events as own_event on own_event.id = ordered.event_id
            left join shows as ordered_show on ordered_show.id = ordered.show_id
            left join events as show_event on show_event.id = ordered_show.event_id
            where ordered.id = recordings.id)';

        return $query
            ->orderByRaw("({$runStart}) is null")
            ->orderByRaw("({$runStart}) desc");
    }

    /**
     * How many the filter finds in each run, in the order the grid puts them.
     *
     * @return array<int, array<string, mixed>>
     */
    private function groupCounts($user, array $filters): array
    {
        $recordings = $this->publishedRecordings($user, $filters['search'])
            ->inCategory($filters['category'])
            ->with(['event:id,name,slug,starts_on', 'show:id,event_id', 'show.event:id,name,slug,starts_on'])
            ->get(['id', 'date', 'event_id', 'show_id']);

        return $recordings
            ->groupBy(fn (Recording $recording) => $recording->effectiveEvent()?->slug ?? '')
            ->map(function (Collection $group, string $slug) {
                $event = $group->first()->effectiveEvent();

                return [
                    'key' => $slug === '' ? 'unfiled' : $slug,
                    // The label the section is headed with, and what the client
                    // matches its tiles against.
                    'label' => $event?->name,
                    'count' => $group->count(),
                    'sort' => $event?->starts_on?->timestamp ?? -1,
                ];
            })
            ->sortByDesc('sort')
            ->map(fn (array $group) => Arr::except($group, 'sort'))
            ->values()
            ->all();
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
            ->with([
                'recording.source:id,name,slug',
                'recording.category:id,name,slug',
                'recording.event:id,name,slug',
                'recording.show:id,category_id,event_id',
                'recording.show.category:id,name,slug',
                'recording.show.event:id,name,slug',
            ])
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
            // What run it belongs to, so a grid read run by run can start a new
            // section when this changes without asking the server again.
            'event_label' => $recording->effectiveEvent()?->name,
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
        // not been published yet. `publish_plan` is the promise; it says nothing about
        // capture, which happens for every source unconditionally.
        $query = Show::where('publish_plan', 'yes')
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
            ->filter(fn (Show $show) => $filters['category'] === null || $show->category?->slug === $filters['category'])
            ->map(fn (Show $show) => $this->pendingTile($show))
            ->values();
    }

    /**
     * One pending show as a tile. Dates go out as ISO strings, matching how the
     * models serialise theirs, so a merged list sorts on one comparable type.
     */
    private function pendingTile(Show $show): array
    {
        return [
            'id' => 'pending-'.$show->id,
            'title' => $show->title,
            'date' => ($show->actual_end ?? $show->scheduled_end)?->toJSON(),
            'thumbnail_url' => null,
            'duration' => null,
            'views' => 0,
            'source_name' => $show->source?->name,
            'source_slug' => $show->source?->slug,
            'category_name' => $show->category?->name,
            'event_label' => $show->event?->name,
            'preview_url' => null,
            'progress' => null,
            'is_pending' => true,
        ];
    }

    public function show(Request $request, Recording $recording)
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

        // One view per viewer per window, not one per render; see RecordingViews.
        RecordingViews::count($recording, $request);

        $recording->load(['source:id,name,slug', 'category:id,name,slug', 'event:id,name,slug', 'show:id,category_id,event_id', 'show.category:id,name,slug', 'show.event:id,name,slug']);

        $upNext = $this->upNext($recording, $user);

        $thread = RecordingCommentController::thread(
            $recording,
            $user,
            RecordingCommentController::limitFrom($request),
        );

        return Inertia::render('RecordingPlayer', [
            'recording' => $recording,
            'sourceName' => $recording->source?->name,
            // Stretches the player may offer a way past. Sorted and non-overlapping,
            // so the player can walk them without deciding anything.
            'skips' => $recording->skips(),
            /*
             * The operator's half of the page, and null for everybody else: a
             * viewer is offered the skip button, never the marking of it. Absent
             * rather than a false flag, so nothing about the tools reaches a
             * browser that may not use them.
             */
            'tools' => $user?->can('update', $recording) ? [
                'skipsUrl' => route('recordings.skips', $recording),
                'duration' => (int) $recording->duration,
                'manageUrl' => route('manage.recordings.edit', $recording),
            ] : null,
            'category' => $recording->effectiveCategory()?->only(['name', 'slug']),
            'upNext' => $upNext,
            /*
             * The thread, most hearted first, and how much of it is on screen.
             * Shared as ordinary props rather than fetched: posting, hearting and
             * Load more are all Inertia visits, so the page re-renders with the
             * answer in place.
             */
            'comments' => $thread['comments'],
            'commentsMeta' => $thread['meta'],
            'commentsEnabled' => RecordingCommentController::availableTo($user),
            // A guest sees the thread and is told where to sign in; there is nothing
            // to attribute a guest's comment to.
            'canComment' => $user !== null,
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
    /**
     * Mark skip points from the watch page.
     *
     * The same column the recording form writes, from the other side of the glass:
     * an operator watching a recording is the one who notices the intermission, and
     * making them find it again in /manage is how it stays unmarked. Only the
     * ranges - nothing here can touch the cut, the title or whether it is published.
     */
    public function updateSkips(Request $request, Recording $recording)
    {
        $this->authorize('update', $recording);

        $data = $request->validate([
            'skip_segments' => ['present', 'array', 'max:'.SkipSegments::MAX],
            'skip_segments.*.start' => ['required', 'numeric', 'min:0'],
            'skip_segments.*.end' => ['required', 'numeric', 'min:0', 'gt:skip_segments.*.start'],
            'skip_segments.*.label' => ['nullable', 'string', 'max:'.SkipSegments::LABEL_MAX],
        ]);

        $recording->forceFill([
            'skip_segments' => SkipSegments::normalise($data['skip_segments'], $recording->duration),
        ])->save();

        return back();
    }

    private function upNext(Recording $recording, $user): Collection
    {
        $take = 15;

        $sameSource = $recording->source_id
            ? $this->publishedRecordings($user, null)
                ->with(['source:id,name,slug', 'category:id,name,slug', 'event:id,name,slug', 'show:id,category_id,event_id', 'show.category:id,name,slug', 'show.event:id,name,slug'])
                ->where('id', '!=', $recording->id)
                ->where('source_id', $recording->source_id)
                ->orderByDesc('date')
                ->limit($take)
                ->get()
            : collect();

        $fill = collect();

        if ($sameSource->count() < $take && $recording->date) {
            $fill = $this->publishedRecordings($user, null)
                ->with(['source:id,name,slug', 'category:id,name,slug', 'event:id,name,slug', 'show:id,category_id,event_id', 'show.category:id,name,slug', 'show.event:id,name,slug'])
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
