<?php

namespace App\Http\Controllers;

use App\Models\Show;
use App\Services\BoopCounter;
use App\Support\Features;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Boops: the paw on the player, clicked as often as a viewer likes.
 *
 * Nothing is attributed and nothing can be taken back, so this is deliberately
 * open to guests as well. What keeps an unbounded click counter cheap is that
 * this request writes nothing: the client posts batches rather than single
 * clicks, and those land on a cache counter that FlushShowBoopsJob banks and
 * broadcasts on a tick. See App\Services\BoopCounter.
 */
class BoopController extends Controller
{
    private const MAX_PER_REQUEST = 50;

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

        $count = (int) $data['count'];

        return response()->json([
            'total' => $counter->add($show, $count),
            'accepted' => $count,
        ]);
    }
}
