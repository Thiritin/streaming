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

        /*
         * The length the player measured wins over the one on the record.
         *
         * `recordings.duration` is metadata: read off a playlist once, typed in by
         * hand for an import, and wrong whenever a recording is re-cut without it
         * being refreshed. The media element knows what it is playing. Storing the
         * record's number here is what put a tile's progress bar and the bar under
         * the player on different scales - a recording watched to the end reading
         * as a fifth watched on the archive page.
         *
         * Bounded, because it arrives from a client: anything outside a plausible
         * range for a recording falls back to what the record says.
         */
        $reported = (int) round($data['duration'] ?? 0);
        $duration = $reported >= 1 && $reported <= 24 * 3600
            ? $reported
            : (int) $recording->duration;

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
