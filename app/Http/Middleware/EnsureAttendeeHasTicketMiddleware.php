<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Session;

class EnsureAttendeeHasTicketMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if ($user === null) {
            throw new \Exception("User is not logged in.");
        }

        if ($user->is_attendee) {
            return $next($request);
        }

        // User is not attendee, check authentication status
        $attendeeListResponse = Http::attsrv()->get('/attendees');
        $regId = $attendeeListResponse->json()['ids'][0];
        $statusResponse = Http::attsrv()->get('/attendees/'.$regId.'/status');

        // paid or checked in
        if (in_array($statusResponse->json()['status'], ['paid', 'checked in'])) {
            $user->is_attendee = true;
            $user->reg_id = $regId;
            $user->save();
            return $next($request);
        }

        // if user is not still not attendee by reg sys redirect to denial page

        // if user is now attendee, update his database entry and let him pass

        $user->reg_id = $regId;

        return $next($request);
    }
}
