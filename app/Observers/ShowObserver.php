<?php

namespace App\Observers;

use App\Events\ShowCancelled;
use App\Events\ShowEnded;
use App\Events\ShowWentLive;
use App\Models\Show;
use Illuminate\Support\Facades\Log;

class ShowObserver
{
    /**
     * Handle the Show "updated" event.
     * Fire appropriate events when status changes.
     */
    public function updated(Show $show): void
    {
        // Check if status was changed
        if ($show->wasChanged('status')) {
            $previousStatus = $show->getOriginal('status');
            $newStatus = $show->status;
            
            Log::info('Show status changed via Observer', [
                'show_id' => $show->id,
                'show_title' => $show->title,
                'previous_status' => $previousStatus,
                'new_status' => $newStatus,
            ]);
            
            // Fire appropriate event based on new status
            switch ($newStatus) {
                case 'live':
                    // Only fire if we're transitioning TO live from another status
                    if ($previousStatus !== 'live') {
                        // Update actual_start if not already set
                        if (!$show->actual_start) {
                            $show->actual_start = now();
                            $show->saveQuietly(); // Save without triggering events again
                        }
                        event(new ShowWentLive($show));
                        Log::info('ShowWentLive event fired for show', ['show_id' => $show->id]);
                    }
                    break;
                    
                case 'ended':
                    // Only fire if we're transitioning TO ended from another status
                    if ($previousStatus !== 'ended') {
                        // Update actual_end if not already set
                        if (!$show->actual_end) {
                            $show->actual_end = now();
                            $show->saveQuietly(); // Save without triggering events again
                        }
                        
                        // Mark all active viewers as left in the source
                        if ($show->source) {
                            $show->source->activeViewers()->update([
                                'left_at' => now(),
                            ]);
                        }
                        
                        event(new ShowEnded($show));
                        Log::info('ShowEnded event fired for show', ['show_id' => $show->id]);
                    }
                    break;
                    
                case 'cancelled':
                    // Only fire if we're transitioning TO cancelled from another status
                    if ($previousStatus !== 'cancelled') {
                        event(new ShowCancelled($show));
                        Log::info('ShowCancelled event fired for show', ['show_id' => $show->id]);
                    }
                    break;
            }
        }
    }
}