<?php

namespace App\Http\Controllers;

use App\Models\Recording;
use App\Models\RecordingProgress;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class RecordingProgressController extends Controller
{
    /**
     * Save where the viewer is in a recording.
     *
     * Answers 204 rather than a redirect: the player posts this from a plain fetch
     * every few seconds and on the way out, and an Inertia redirect would re-render
     * the page under a viewer who is watching it.
     */
    public function update(Request $request, Recording $recording)
    {
        $user = Auth::user();

        if (! $recording->is_published || ! $recording->canBeAccessedBy($user)) {
            abort(404);
        }

        $data = $request->validate([
            'position' => ['required', 'numeric', 'min:0'],
            'duration' => ['nullable', 'numeric', 'min:0'],
        ]);

        // The recording's own duration wins where it has one: a client-reported
        // length is whatever the player happened to have buffered.
        $duration = (int) ($recording->duration ?: round($data['duration'] ?? 0));
        $position = (int) round($data['position']);

        if ($duration > 0) {
            $position = min($position, $duration);
        }

        RecordingProgress::updateOrCreate(
            ['user_id' => $user->id, 'recording_id' => $recording->id],
            [
                'position' => $position,
                'duration' => $duration ?: null,
                'completed' => $duration > 0 && $position / $duration >= RecordingProgress::COMPLETE_AT,
            ]
        );

        return response()->noContent();
    }
}
