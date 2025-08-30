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
        
        // Ensure user is marked as provisioning so they're in the queue
        if (!$user->is_provisioning) {
            $user->update(['is_provisioning' => true]);
            $user->refresh();
        }
        
        // Get accurate queue position based on when they were marked as provisioning
        $queuePosition = User::where('is_provisioning', true)
            ->where('updated_at', '<', $user->updated_at)
            ->count() + 1;
        
        $totalWaiting = User::where('is_provisioning', true)->count();
        
        return Inertia::render('ProvisioningWait', [
            'queuePosition' => $queuePosition,
            'totalWaiting' => $totalWaiting,
            'userId' => $user->id,
            'isProvisioning' => true, // Always true on this page
        ]);
    }
}