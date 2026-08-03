<?php

namespace App\Http\Controllers;

use App\Models\Recording;
use App\Services\ArchivePlaylistService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves recording playlists, rendered per request.
 *
 * Playlists are not stored anywhere. Segments are handed out as presigned URLs, which
 * expire, so a stored playlist would stop working a day after it was written. Rendering
 * on demand also puts the access check on the request that mints the URLs, which is the
 * only place `required_roles` can actually be enforced: once a signed segment URL exists,
 * whoever holds it can fetch it.
 *
 * Recordings are ordinary VOD and deliberately do not go through the edge or the playback
 * token. There is nothing to protect on the media path that the signature does not already
 * cover, and no live capacity to spread.
 */
class RecordingPlaylistController extends Controller
{
    public function __construct(protected ArchivePlaylistService $playlists) {}

    public function master(Request $request, string $slug)
    {
        $recording = $this->authorized($slug);

        return $this->playlist($this->playlists->renderMaster($recording));
    }

    public function media(Request $request, string $slug, string $rendition)
    {
        $recording = $this->authorized($slug);

        if (! in_array($rendition, $this->playlists->renditions(), true)) {
            abort(404);
        }

        try {
            $body = $this->playlists->renderMedia($recording, $rendition);
        } catch (\Throwable $e) {
            // A cut whose segments have expired out of the archive, most likely.
            abort(Response::HTTP_GONE, $e->getMessage());
        }

        return $this->playlist($body);
    }

    /**
     * Unpublished recordings are invisible to everyone but an operator who may edit them,
     * so a draft can be previewed in the manage panel before it goes out.
     */
    protected function authorized(string $slug): Recording
    {
        $recording = Recording::where('slug', $slug)->firstOrFail();
        $user = Auth::user();

        if (! $recording->is_published) {
            abort_unless($user?->can('update', $recording), 404);

            return $recording;
        }

        abort_unless($recording->canBeAccessedBy($user), 403);

        return $recording;
    }

    protected function playlist(string $body)
    {
        return response($body, 200, [
            'Content-Type' => 'application/vnd.apple.mpegurl',
            // The URLs inside are signed and time-limited, so a cached copy would hand
            // out credentials and would also outlive them.
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
