<?php

namespace App\Http\Controllers;

use App\Models\Recording;
use Illuminate\Http\Request;
use Inertia\Inertia;

class RecordingController extends Controller
{
    public function index()
    {
        $recordings = Recording::where('is_published', true)
            ->orderBy('date', 'desc')
            ->get();

        return Inertia::render('Recordings', [
            'recordings' => $recordings
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