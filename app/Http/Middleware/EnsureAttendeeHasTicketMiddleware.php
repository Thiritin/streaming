<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redirect;

class EnsureAttendeeHasTicketMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if ($user === null) {
            throw new \Exception('User is not logged in.');
        }

        // If user already has permission, then he is atleast an attendee
        if ($user->hasPermissionTo('stream.view')) {
            return $next($request);
        }

        // User is not attendee, check authentication status
        $attendeeListResponse = Http::attsrv()->get('/attendees');
        $regId = $attendeeListResponse->json()['ids'][0] ?? null;

        if ($regId === null) {
            return $this->abort($request, $next);
        }

        $statusResponse = Http::attsrv()->get('/attendees/'.$regId.'/status');

        // paid or checked in
        if (in_array($statusResponse->json()['status'], ['paid', 'checked in'])) {
            // Check user level
            $role = 'Attendee';
            $isSponsorRequest = Http::attsrv()->get('/attendees/'.$regId.'/packages/sponsor');
            // User is now attendee, update his database entry and let him pass
            if ($isSponsorRequest->json()['present'] === true) {
                $role = 'Sponsor';
            } else {
                $isSuperSponsorRequest = Http::attsrv()->get('/attendees/'.$regId.'/packages/sponsor2');
                if ($isSuperSponsorRequest->json()['present'] === true) {
                    $role = 'Super Sponsor';
                }
            }

            $user->assignRole($role);
            $user->reg_id = $regId;
            $user->save();
        }

        $this->abort($request, $next);

        // Redirect is needed to make sure roles are properly reloaded
        return Redirect::route('shows.grid');
    }

    public function abort(Request $request, Closure $next)
    {
        Auth::user()->assignRole('Digital Pass');
    }
}
