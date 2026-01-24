<?php

namespace App\Http\Controllers;

use App\Models\Recording;
use App\Models\Show;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class RecordingController extends Controller
{
    public function index(Request $request)
    {
        $user = Auth::user();
        $search = $request->get('search');

        // Get published recordings with optional search (filtered by access)
        $recordingsQuery = Recording::where('is_published', true)
            ->accessibleBy($user);

        if ($search) {
            $recordingsQuery->where(function ($query) use ($search) {
                $query->where('title', 'like', '%'.$search.'%')
                    ->orWhere('description', 'like', '%'.$search.'%');
            });
        }

        $recordings = $recordingsQuery->orderBy('date', 'desc')->get();

        // Get pending recordings (shows that are recordable but don't have a recording yet)
        $pendingShowsQuery = Show::where('recordable', true)
            ->where('status', 'ended')
            ->doesntHave('recording');

        if ($search) {
            $pendingShowsQuery->where(function ($query) use ($search) {
                $query->where('title', 'like', '%'.$search.'%')
                    ->orWhere('description', 'like', '%'.$search.'%');
            });
        }

        $pendingShows = $pendingShowsQuery
            ->orderBy('actual_end', 'desc')
            ->orderBy('scheduled_end', 'desc')
            ->get();

        return Inertia::render('Recordings', [
            'recordings' => $recordings,
            'pendingShows' => $pendingShows,
            'search' => $search,
        ]);
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
