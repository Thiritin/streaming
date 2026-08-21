<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessRecordingJob;
use App\Services\ArchiveImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * The import CLI's half of the archive import flow.
 *
 * Three calls: open an import and learn what to encode, get upload URLs for the segments
 * produced, then commit. See ArchiveImportService for why the split falls where it does.
 * Authenticated with the recording API key, same as the other endpoints in this prefix.
 */
class ArchiveImportController extends Controller
{
    public function __construct(protected ArchiveImportService $imports) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255|regex:/^[a-z0-9-]+$/|unique:recordings,slug',
            'description' => 'nullable|string|max:5000',
            'date' => 'nullable|date',
            'prefix' => 'nullable|string|max:64',
            'show_id' => 'nullable|integer|exists:shows,id',
            'source_id' => 'nullable|integer|exists:sources,id',
            'required_roles' => 'nullable|array',
            'required_roles.*' => 'string|exists:roles,slug',
        ]);

        try {
            $started = $this->imports->start($validated);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'data' => $started], 201);
    }

    public function urls(Request $request, string $import): JsonResponse
    {
        $validated = $request->validate([
            'segments' => 'required|array|min:1|max:1000',
            'segments.*.rendition' => 'required|string|max:16',
            'segments.*.number' => 'required|integer|min:0',
            'segments.*.hour' => 'required|string|max:11',
        ]);

        $record = $this->imports->find($import);

        if (! $record) {
            return response()->json(['error' => 'Unknown or expired import.'], 404);
        }

        try {
            $urls = $this->imports->presign($record, $validated['segments']);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'data' => $urls]);
    }

    public function commit(Request $request, string $import): JsonResponse
    {
        $validated = $request->validate([
            'renditions' => 'required|array|min:1',
            'renditions.*' => 'string|max:16',
            'segments' => 'required|array|min:1',
            'segments.*.number' => 'required|integer|min:0',
            'segments.*.duration' => 'required|numeric|min:0.001|max:60',
        ]);

        $record = $this->imports->find($import);

        if (! $record) {
            return response()->json(['error' => 'Unknown or expired import.'], 404);
        }

        try {
            $recording = $this->imports->commit($record, $validated['segments'], $validated['renditions']);
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            // The service's own vocabulary: a segment did not land, the import was already
            // committed, the range resolves to nothing. All of it is the client's to act on.
            return response()->json(['error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            Log::error('Archive import commit failed: '.$e->getMessage(), ['import' => $import, 'exception' => $e]);

            return response()->json(['error' => 'The import could not be committed.'], 500);
        }

        // Duration and segment count come from the build; this is only the thumbnail, which
        // wants ffmpeg and a queue rather than a request.
        ProcessRecordingJob::dispatch($recording);

        return response()->json([
            'success' => true,
            'data' => [
                'recording_id' => $recording->id,
                'slug' => $recording->slug,
                'duration' => $recording->duration,
                'segment_count' => $recording->segment_count,
                'status' => $recording->status,
                'is_published' => (bool) $recording->is_published,
                'manage_url' => route('manage.recordings.edit', $recording),
                'watch_url' => route('recordings.show', $recording->slug),
            ],
        ], 201);
    }
}
