<?php

namespace App\Listeners;

use App\Enum\SourceStatusEnum;
use App\Events\SourceStatusChangedEvent;
use App\Models\Show;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class HandleAutoModeShowsListener implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * Handle the event.
     */
    public function handle(SourceStatusChangedEvent $event): void
    {
        $source = $event->source;
        $previousStatus = $event->previousStatus;
        $currentStatus = $source->status;

        Log::info('HandleAutoModeShowsListener: Processing source status change', [
            'source_id' => $source->id,
            'source_name' => $source->name,
            'previous_status' => $previousStatus,
            'current_status' => $currentStatus->value,
        ]);

        // Get all auto mode shows for this source
        $autoModeShows = Show::where('source_id', $source->id)
            ->where('auto_mode', true)
            ->whereIn('status', ['scheduled', 'live'])
            ->get();

        if ($autoModeShows->isEmpty()) {
            Log::info('HandleAutoModeShowsListener: No auto mode shows found for source', [
                'source_id' => $source->id,
            ]);
            return;
        }

        foreach ($autoModeShows as $show) {
            $this->handleShowAutoMode($show, $currentStatus, $previousStatus);
        }
    }

    /**
     * Handle auto mode for a specific show based on source status change.
     */
    private function handleShowAutoMode(Show $show, SourceStatusEnum $currentStatus, string $previousStatus): void
    {
        Log::info('HandleAutoModeShowsListener: Processing auto mode show', [
            'show_id' => $show->id,
            'show_title' => $show->title,
            'show_status' => $show->status,
            'source_status' => $currentStatus->value,
            'is_within_scheduled_time' => $show->isWithinScheduledTime(),
            'is_past_scheduled_end' => $show->isPastScheduledEnd(),
        ]);

        // Handle based on source status
        switch ($currentStatus) {
            case SourceStatusEnum::ONLINE:
                $this->handleSourceOnline($show);
                break;

            case SourceStatusEnum::OFFLINE:
                $this->handleSourceOffline($show);
                break;

            case SourceStatusEnum::ERROR:
                // For error status during the event, we do nothing as per requirements
                // The frontend will show error status but the show remains in its current state
                if ($show->isWithinScheduledTime()) {
                    Log::info('HandleAutoModeShowsListener: Source error during scheduled time - no action taken', [
                        'show_id' => $show->id,
                    ]);
                } else {
                    // If error happens outside scheduled time, treat it like offline
                    $this->handleSourceOffline($show);
                }
                break;
        }
    }

    /**
     * Handle when source goes online.
     */
    private function handleSourceOnline(Show $show): void
    {
        // Only start the show if:
        // 1. It's scheduled (not already live)
        // 2. It's within the scheduled time window OR past the scheduled start time
        if ($show->status === 'scheduled') {
            $now = now();
            
            // Check if we should start the show
            if ($show->scheduled_start <= $now) {
                Log::info('HandleAutoModeShowsListener: Auto-starting show - source online and past scheduled start', [
                    'show_id' => $show->id,
                    'show_title' => $show->title,
                    'scheduled_start' => $show->scheduled_start,
                    'current_time' => $now,
                ]);

                $show->goLive();

                Log::info('HandleAutoModeShowsListener: Show auto-started successfully', [
                    'show_id' => $show->id,
                    'show_title' => $show->title,
                ]);
            } else {
                Log::info('HandleAutoModeShowsListener: Source online but show not yet at scheduled start time', [
                    'show_id' => $show->id,
                    'scheduled_start' => $show->scheduled_start,
                    'current_time' => $now,
                ]);
            }
        }
    }

    /**
     * Handle when source goes offline.
     */
    private function handleSourceOffline(Show $show): void
    {
        // Only end the show if:
        // 1. It's currently live
        // 2. It's past the scheduled end time
        if ($show->status === 'live' && $show->isPastScheduledEnd()) {
            Log::info('HandleAutoModeShowsListener: Auto-ending show - source offline and past scheduled end', [
                'show_id' => $show->id,
                'show_title' => $show->title,
                'scheduled_end' => $show->scheduled_end,
                'current_time' => now(),
            ]);

            $show->endLivestream();

            Log::info('HandleAutoModeShowsListener: Show auto-ended successfully', [
                'show_id' => $show->id,
                'show_title' => $show->title,
            ]);
        } else if ($show->status === 'live' && $show->isWithinScheduledTime()) {
            Log::info('HandleAutoModeShowsListener: Source offline during scheduled time - keeping show live', [
                'show_id' => $show->id,
                'show_title' => $show->title,
                'info' => 'Show remains live, frontend will show offline/error status',
            ]);
        }
    }
}