<?php

namespace App\Http\Controllers;

use App\Models\Recording;
use App\Models\Show;
use Illuminate\Http\Request;
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

        return Inertia::render('Archive/Index', [
            'collections' => $collections,
            'recentRecordings' => $recordings->take(8)->values(),
            'searchResults' => $search ? $recordings->values() : null,
            'totalRecordings' => $recordings->count(),
            'pendingCount' => $this->pendingShows($search)->count(),
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

        if ($recordings->isEmpty() && ! $search) {
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

        // Shows that ended but whose recording has not been published yet, so the
        // year does not look like it is missing something without explanation.
        $pendingShows = $this->pendingShows($search)
            ->filter(fn (Show $show) => ($show->actual_end ?? $show->scheduled_end)?->year === $year)
            ->values();

        return Inertia::render('Archive/Year', [
            'year' => $year,
            'years' => $years,
            'recordings' => $recordings->values(),
            'pendingShows' => $pendingShows,
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
        $query = Show::where('recordable', true)
            ->where('status', 'ended')
            ->doesntHave('recording');

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
