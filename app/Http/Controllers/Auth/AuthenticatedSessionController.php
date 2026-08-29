<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\Show;
use App\Providers\RouteServiceProvider;
use App\Support\AuthModes;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

/**
 * The sign-in screen and the session behind it.
 *
 * The screen renders whatever modes are on, down to none of them, so it is not itself
 * behind any of the switches; the POST is, because a form that is not offered must not
 * be reachable by hand either.
 */
class AuthenticatedSessionController extends Controller
{
    /**
     * How far ahead the schedule rail on the login screen looks.
     */
    private const SCHEDULE_WINDOW_HOURS = 20;

    /**
     * How many rows it shows, however much fits in that window.
     */
    private const SCHEDULE_LENGTH = 6;

    public function create(Request $request)
    {
        return Inertia::render('Auth/Login', [
            'schedule' => $this->schedule(),
            'modes' => AuthModes::forFrontend(),
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Sign in with a password.
     *
     * The attempt is scoped to accounts that hold one, so a row the identity provider
     * owns can never be authenticated here however its address was written. The session
     * is regenerated, not flushed: flushing takes the CSRF token and the intended URL
     * with it and presents as a 419 on the next request.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        return redirect()->intended(RouteServiceProvider::HOME);
    }

    /**
     * Sign out of a local session. An account the identity provider owns leaves
     * through auth.frontchannel-logout instead, so the provider hears about it.
     */
    public function destroy(Request $request): RedirectResponse
    {
        /*
         * An account the identity provider owns leaves through the front channel, or
         * the provider is never told and its next /auth/login signs the same person
         * straight back in without asking anything.
         */
        if (! $request->user()->isLocal()) {
            return redirect()->route('auth.frontchannel-logout');
        }

        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect(RouteServiceProvider::HOME);
    }

    /**
     * Whatever is on now, plus anything starting in the next 20 hours, in clock
     * order. Ended and cancelled shows never appear.
     */
    private function schedule(): Collection
    {
        return Show::query()
            // accessibleBy(null) keeps role-restricted shows out of the rail,
            // which is rendered before anyone has signed in.
            ->accessibleBy(null)
            ->with('source')
            ->where(function (Builder $query) {
                // Live shows stay listed no matter when they were scheduled to
                // start, since they are on right now.
                $query->where('status', 'live')
                    ->orWhere(fn (Builder $upcoming) => $upcoming
                        ->where('status', 'scheduled')
                        ->whereBetween('scheduled_start', [
                            now(),
                            now()->addHours(self::SCHEDULE_WINDOW_HOURS),
                        ]));
            })
            // Live shows sort by when they actually started, so a stream that has
            // been running since yesterday stays above tonight's line-up.
            ->orderByRaw('COALESCE(actual_start, scheduled_start)')
            ->limit(self::SCHEDULE_LENGTH)
            ->get()
            ->map(fn (Show $show) => [
                'id' => $show->id,
                'title' => $show->title,
                'source' => $show->source?->name,
                'time' => ($show->actual_start ?? $show->scheduled_start)?->format('H:i'),
                // Drives both the highlighted row and the LIVE marker.
                'current' => $show->status === 'live',
            ])
            ->values();
    }
}
