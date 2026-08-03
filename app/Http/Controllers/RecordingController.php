<?php

namespace App\Http\Controllers;

use App\Models\Recording;
use App\Models\Show;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class RecordingController extends Controller
{
    /**
     * Archive landing page: one collection per convention year.
     *
     * Searching switches the page from collections to flat results, because when you
     * are hunting for one show, year boundaries only get in the way.
     */
    public function index(Request $request)
    {
        $user = Auth::user();
        $search = $request->get('search');

        $recordings = $this->publishedRecordings($user, $search)
            ->orderBy('date', 'desc')
            ->get();

        $collections = $recordings
            ->groupBy(fn (Recording $recording) => $recording->date?->year ?? 0)
            ->map(fn ($yearRecordings, $year) => [
                'year' => (int) $year,
                'count' => $yearRecordings->count(),
                'total_views' => (int) $yearRecordings->sum('views'),
                // Runtime in hours reads better than a raw second count on a card.
                'hours' => (int) round($yearRecordings->sum('duration') / 3600),
                'first_date' => $yearRecordings->min('date'),
                'last_date' => $yearRecordings->max('date'),
                // Poster art: the most watched recording of the year that actually has a still.
                'thumbnail_url' => $yearRecordings
                    ->sortByDesc('views')
                    ->first(fn (Recording $recording) => (bool) $recording->thumbnail_url)
                    ?->thumbnail_url,
                'highlights' => $yearRecordings
                    ->sortByDesc('views')
                    ->take(3)
                    ->pluck('title')
                    ->values(),
            ])
            ->sortByDesc('year')
            ->values();

        // Shows still processing sit in the same grid as everything else, newest first,
        // as dimmed tiles. They are not their own section: a viewer looking for a show
        // should find it where they expect it, told it is not ready yet.
        $withPending = $this->withPendingTiles($recordings, $this->pendingTiles($search));

        return Inertia::render('Archive/Index', [
            'collections' => $collections,
            'recentRecordings' => $withPending->take(8)->values(),
            'searchResults' => $search ? $withPending->values() : null,
            'totalRecordings' => $recordings->count(),
            'search' => $search,
        ]);
    }

    /**
     * One year's collection, laid out like the browse grid.
     */
    public function year(Request $request, int $year)
    {
        $user = Auth::user();
        $search = $request->get('search');

        $recordings = $this->publishedRecordings($user, $search)
            ->whereYear('date', $year)
            ->orderBy('date', 'desc')
            ->get();

        // Shows that ended but whose recording has not been published yet, so the
        // year does not look like it is missing something without explanation.
        $pending = $this->pendingTiles($search, $year);

        if ($recordings->isEmpty() && $pending->isEmpty() && ! $search) {
            abort(404);
        }

        $years = Recording::where('is_published', true)
            ->accessibleBy($user)
            ->orderBy('date', 'desc')
            ->get()
            ->map(fn (Recording $recording) => $recording->date?->year)
            ->filter()
            ->unique()
            ->sortDesc()
            ->values();

        return Inertia::render('Archive/Year', [
            'year' => $year,
            'years' => $years,
            'recordings' => $this->withPendingTiles($recordings, $pending)->values(),
            'totalViews' => (int) $recordings->sum('views'),
            'hours' => (int) round($recordings->sum('duration') / 3600),
            'search' => $search,
        ]);
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
     * Pending shows shaped like recordings, so the same tile renders both. `is_pending`
     * is what the tile keys off to dim itself and drop its link.
     */
    private function pendingTiles(?string $search, ?int $year = null): Collection
    {
        return $this->pendingShows($search)
            ->filter(fn (Show $show) => $year === null
                || ($show->actual_end ?? $show->scheduled_end)?->year === $year)
            // Dates go out as ISO strings, matching how the models serialise theirs, so
            // the merged list sorts on one comparable type.
            ->map(fn (Show $show) => [
                'id' => 'pending-'.$show->id,
                'title' => $show->title,
                'description' => $show->description,
                'description_html' => $show->description_html,
                'date' => ($show->actual_end ?? $show->scheduled_end)?->toJSON(),
                'thumbnail_url' => null,
                'duration' => null,
                'views' => 0,
                'is_pending' => true,
            ])
            ->values();
    }

    /**
     * One date-ordered list of published recordings and pending tiles.
     */
    private function withPendingTiles(Collection $recordings, Collection $pending): Collection
    {
        return $recordings
            ->map(fn (Recording $recording) => $recording->toArray() + ['is_pending' => false])
            ->concat($pending)
            ->sortByDesc('date')
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

        return Inertia::render('RecordingPlayer', [
            'recording' => $recording,
        ]);
    }
}
