<?php

namespace App\Http\Controllers;

use App\Models\Recording;
use App\Services\ArchivePlaylistService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
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
        } catch (\RuntimeException $e) {
            // The service's own vocabulary: the archive expired, or this recording was
            // never a cut. Both are worth telling the caller verbatim.
            abort(Response::HTTP_GONE, $e->getMessage());
        } catch (\Throwable $e) {
            // Anything else is a fault, and its message is not ours to hand out - a
            // TypeError here used to answer 410 with an absolute server path in the
            // body. Log the detail, tell the caller nothing.
            Log::error("Recording playlist render failed for {$recording->slug}: ".$e->getMessage(), [
                'recording' => $recording->id,
                'rendition' => $rendition,
                'exception' => $e,
            ]);

            abort(Response::HTTP_INTERNAL_SERVER_ERROR);
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
            // Private, because the URLs inside are signed: a shared cache would hand out
            // credentials to whoever asked next. The viewer's own browser may keep it
            // briefly - a reload or a second player on the same page then skips several
            // megabytes of playlist - but far short of the signatures' lifetime, so a
            // re-trimmed recording is never played from a stale copy for long.
            'Cache-Control' => 'private, max-age=300',
        ]);
    }
}
