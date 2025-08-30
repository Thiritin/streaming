<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureServerAssigned
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return redirect()->route('auth.login');
        }

        // Check if user has server assignment
        if (! $user->server_id || ! $user->streamkey) {
            // Don't redirect if already on provisioning page to avoid loop
            if ($request->routeIs('provisioning.wait')) {
                return $next($request);
            }

            // Try to assign server
            $assigned = $user->assignServerToUser();

            if (! $assigned) {
                // If assignment failed and user isn't marked as provisioning,
                // they need to be added to the queue
                if (! $user->is_provisioning) {
                    $user->update(['is_provisioning' => true]);
                }

                // Redirect to waiting queue
                return redirect()->route('provisioning.wait');
            }

            // Server was successfully assigned, refresh user data
            $user->refresh();
        }

        return $next($request);
    }
}
