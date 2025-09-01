<?php

namespace App\Observers;

use App\Events\SourceStatusChangedEvent;
use App\Models\Source;
use Illuminate\Support\Facades\Log;

class SourceObserver
{
    /**
     * Temporary storage for previous status values during updates.
     * Using static to persist across observer method calls.
     */
    private static array $previousStatuses = [];

    /**
     * Handle the Source "updating" event.
     * Store the original status before update.
     */
    public function updating(Source $source): void
    {
        // Store the original status value for comparison after update
        $originalStatus = $source->getOriginal('status');
        
        if ($originalStatus instanceof \App\Enum\SourceStatusEnum) {
            $originalStatus = $originalStatus->value;
        }
        
        self::$previousStatuses[$source->id] = $originalStatus;
    }

    /**
     * Handle the Source "updated" event.
     * Broadcast status change if status was changed.
     */
    public function updated(Source $source): void
    {
        // Check if status was changed
        if ($source->wasChanged('status')) {
            // Get the previous status from our static storage
            $previousStatus = self::$previousStatuses[$source->id] ?? null;
            
            if ($previousStatus === null) {
                // Fallback to getOriginal if for some reason we don't have it stored
                $previousStatus = $source->getOriginal('status');
                if ($previousStatus instanceof \App\Enum\SourceStatusEnum) {
                    $previousStatus = $previousStatus->value;
                }
            }
            
            Log::info('Source status changed via Observer', [
                'source_id' => $source->id,
                'source_name' => $source->name,
                'previous_status' => $previousStatus,
                'new_status' => $source->status->value,
                'changed_via' => 'admin_panel',
            ]);
            
            // Broadcast the status change event
            broadcast(new SourceStatusChangedEvent($source, $previousStatus));
            
            // Clean up the static storage
            unset(self::$previousStatuses[$source->id]);
        }
    }
}