<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class ProvisioningController extends Controller
{
    /**
     * Show the waiting queue page for users without server assignment
     */
    public function wait(Request $request)
    {
        /** @var User $user */
        $user = Auth::user();

        // If user already has server assignment, redirect to shows
        if ($user->server_id && $user->streamkey) {
            return redirect()->route('shows.grid');
        }

        // Try to assign server one more time (in case one became available)
        $assigned = $user->assignServerToUser();
        if ($assigned) {
            return redirect()->route('shows.grid');
        }

        // Get queue position based on users without server assignment
        $queuePosition = User::whereNull('server_id')
            ->where('updated_at', '<', $user->updated_at)
            ->count() + 1;

        $totalWaiting = User::whereNull('server_id')->count();

        return Inertia::render('ProvisioningWait', [
            'queuePosition' => $queuePosition,
            'totalWaiting' => $totalWaiting,
            'userId' => $user->id,
            'isProvisioning' => true, // Always true on this page
        ]);
    }
}
