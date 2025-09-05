<?php

namespace App\Http\Controllers;

use App\Models\Recording;
use App\Models\Show;
use Illuminate\Http\Request;
use Inertia\Inertia;

class RecordingController extends Controller
{
    public function index(Request $request)
    {
        $search = $request->get('search');
        
        // Get published recordings with optional search
        $recordingsQuery = Recording::where('is_published', true);
        
        if ($search) {
            $recordingsQuery->where(function ($query) use ($search) {
                $query->where('title', 'like', '%' . $search . '%')
                      ->orWhere('description', 'like', '%' . $search . '%');
            });
        }
        
        $recordings = $recordingsQuery->orderBy('date', 'desc')->get();
        
        // Get pending recordings (shows that are recordable but don't have a recording yet)
        $pendingShowsQuery = Show::where('recordable', true)
            ->where('status', 'ended')
            ->doesntHave('recording');
        
        if ($search) {
            $pendingShowsQuery->where(function ($query) use ($search) {
                $query->where('title', 'like', '%' . $search . '%')
                      ->orWhere('description', 'like', '%' . $search . '%');
            });
        }
        
        $pendingShows = $pendingShowsQuery
            ->orderBy('actual_end', 'desc')
            ->orderBy('scheduled_end', 'desc')
            ->get();

        return Inertia::render('Recordings', [
            'recordings' => $recordings,
            'pendingShows' => $pendingShows,
            'search' => $search
        ]);
    }

    public function show(Recording $recording)
    {
        if (!$recording->is_published) {
            abort(404);
        }

        // Increment views
        $recording->increment('views');

        return Inertia::render('RecordingPlayer', [
            'recording' => $recording
        ]);
    }
}