<?php

namespace App\Support;

use App\Models\Recording;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The archive's view counter.
 *
 * A view is somebody sitting down to watch, not a page render. The watch page is
 * an Inertia visit like any other, so a reload, a back button, a comment posted
 * or a heart pressed all render it again, and counting every render made one
 * popular recording a row every viewer queued behind: the increment holds a row
 * lock, and at convention traffic the queue was long enough to hold up every
 * Octane worker in the pool.
 *
 * So one viewer counts once per window. The claim is a cache entry - `Cache::add`,
 * which only lands if nothing holds the key - keyed by the account, or by the
 * session for a guest, and the increment only runs for whoever won the claim.
 *
 * The write goes through the query builder rather than the model, because a view
 * is not an edit: it must not touch `updated_at` and must not wake the observer,
 * which watches for a publish or a retitle.
 */
class RecordingViews
{
    /**
     * How long one viewer's view of one recording stands for.
     */
    private const WINDOW_MINUTES = 30;

    /**
     * Count this render, if it is the first one this viewer has made in the window.
     */
    public static function count(Recording $recording, Request $request): void
    {
        if (! Cache::add(self::key($recording, $request), 1, now()->addMinutes(self::WINDOW_MINUTES))) {
            return;
        }

        DB::table($recording->getTable())
            ->where('id', $recording->getKey())
            ->increment('views');

        $recording->views = (int) $recording->views + 1;
        $recording->syncOriginalAttribute('views');
    }

    /**
     * Who is watching: the account if there is one, else the session a guest
     * carries. Both outlive a reload, which is the whole point.
     */
    private static function key(Recording $recording, Request $request): string
    {
        $viewer = $request->user()?->getAuthIdentifier();

        if ($viewer === null) {
            $viewer = 'guest:'.($request->hasSession()
                ? $request->session()->getId()
                : sha1($request->ip().'|'.$request->userAgent()));
        }

        return 'recording-view:'.$recording->getKey().':'.$viewer;
    }
}
