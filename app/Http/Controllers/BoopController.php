<?php

namespace App\Http\Controllers;

use App\Models\Show;
use App\Services\BoopCounter;
use App\Support\Features;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Boops: the paw on the player, clicked as often as a viewer likes.
 *
 * Nothing is attributed and nothing can be taken back, so this is deliberately
 * open to guests as well. What keeps an unbounded click counter cheap is that
 * this request writes nothing: the client posts batches rather than single
 * clicks, and those land on a cache counter that FlushShowBoopsJob banks and
 * broadcasts on a tick. See App\Services\BoopCounter.
 *
 * The limit is set where a hand stops and a script starts. A viewer mashing the
 * button lands well inside it; an auto-clicker holding 30 a second is cut back
 * to a human's pace rather than refused outright, so the counter stays a room's
 * number and not a script's. Batches over the budget are trimmed instead of
 * rejected - the reply says how many were taken and the client corrects itself.
 */
class BoopController extends Controller
{
    private const MAX_PER_REQUEST = 50;

    /**
     * Sustained boops per viewer per show. Roughly seven a second, which is
     * faster than a hand keeps up for a whole minute and slower than any clicker.
     */
    private const MAX_PER_MINUTE = 400;

    public function store(Request $request, Show $show, BoopCounter $counter): JsonResponse
    {
        $data = $request->validate([
            'count' => ['required', 'integer', 'min:1', 'max:'.self::MAX_PER_REQUEST],
        ]);

        abort_unless(Features::enabledFor('boops', Auth::user()), 404, 'Boops are disabled.');

        if (! $show->canBeAccessedBy(Auth::user())) {
            abort(403);
        }

        if ($show->status !== 'live') {
            return response()->json([
                'total' => $counter->total($show),
                'accepted' => 0,
            ], 409);
        }

        $key = $this->limiterKey($request, $show);
        $accepted = min((int) $data['count'], RateLimiter::remaining($key, self::MAX_PER_MINUTE));

        if ($accepted <= 0) {
            $retryAfter = RateLimiter::availableIn($key);

            return response()->json([
                'total' => $counter->total($show),
                'accepted' => 0,
                'retry_after' => $retryAfter,
            ], 429, ['Retry-After' => $retryAfter]);
        }

        RateLimiter::increment($key, 60, $accepted);

        return response()->json([
            'total' => $counter->add($show, $accepted),
            'accepted' => $accepted,
        ]);
    }

    /**
     * Per viewer and per show: one room's mashing must not spend another's
     * budget, and a shared address is the closest thing a guest has to a name.
     */
    private function limiterKey(Request $request, Show $show): string
    {
        return 'boops:'.$show->getKey().':'.(Auth::id() ?? $request->ip());
    }
}
