<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Recording;
use App\Models\Show;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class RecordingApiController extends Controller
{
    /**
     * Get shows that need to be recorded.
     * Returns shows where recordable=true, actual_start and actual_end are set, and no recording exists.
     */
    public function shows()
    {
        $shows = Show::with('source')
            ->where('recordable', true)
            ->whereNotNull('actual_start')
            ->whereNotNull('actual_end')
            ->whereDoesntHave('recording')
            ->get()
            ->map(function ($show) {
                return [
                    'show_id' => $show->id,
                    'source' => $show->source->slug ?? null,
                    'show' => $show->slug,
                    'start' => $show->actual_start->toIso8601String(),
                    'end' => $show->actual_end->toIso8601String(),
                    'title' => $show->title,
                    'description' => $show->description,
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $shows,
            'count' => $shows->count()
        ]);
    }

    /**
     * Create a new recording.
     */
    public function create(Request $request)
    {
        $validated = $request->validate([
            'show_id' => 'nullable|exists:shows,id',
            'title' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255|unique:recordings,slug',
            'description' => 'nullable|string',
            'm3u8_url' => 'required|url',
            'duration' => 'nullable|integer|min:0',
            'date' => 'nullable|date',
            'is_published' => 'nullable|boolean',
        ]);

        // If show_id is provided, get the show details
        if (!empty($validated['show_id'])) {
            $show = Show::find($validated['show_id']);

            // Use show details if not provided
            if (empty($validated['date']) && $show->actual_start) {
                $validated['date'] = $show->actual_start;
            }

            // Calculate duration if not provided
            if (empty($validated['duration']) && $show->actual_start && $show->actual_end) {
                $validated['duration'] = $show->actual_start->diffInSeconds($show->actual_end);
            }

            // Use show description if not provided
            if (empty($validated['description']) && $show->description) {
                $validated['description'] = $show->description;
            }
        }

        // Generate slug if not provided
        if (empty($validated['slug'])) {
            $baseSlug = Str::slug($validated['title']);
            $slug = $baseSlug;
            $count = 1;

            while (Recording::where('slug', $slug)->exists()) {
                $slug = $baseSlug . '-' . $count;
                $count++;
            }

            $validated['slug'] = $slug;
        }

        // Default to published
        if (!isset($validated['is_published'])) {
            $validated['is_published'] = true;
        }

        // Default date to now if not provided
        if (empty($validated['date'])) {
            $validated['date'] = now();
        }

        // Create the recording
        $recording = Recording::create($validated);

        return response()->json([
            'success' => true,
            'data' => $recording,
            'message' => 'Recording created successfully'
        ], 201);
    }

    /**
     * Get a recording by slug.
     */
    public function getBySlug($slug)
    {
        $recording = Recording::where('slug', $slug)
            ->where('is_published', true)
            ->firstOrFail();

        return response()->json([
            'success' => true,
            'data' => $recording
        ]);
    }
}
