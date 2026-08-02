<?php

namespace App\Console\Commands;

use App\Enum\SourceStatusEnum;
use App\Models\Show;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Drives auto mode, once a minute.
 *
 * Two independent rules, documented in full in docs/admin/auto-mode.md:
 *
 *  1. Start - a scheduled show goes live once its scheduled start has passed *and* its
 *             source is actually online. Without the source check an auto show would go
 *             live to an empty stream.
 *  2. Stop  - a live show ends at its hard stop, whatever the source is doing. The hard
 *             stop is `auto_stop_at`, falling back to `scheduled_end`. This is the safety
 *             net: a dance nobody remembers to end stops itself instead of recording all
 *             night.
 *
 * Only shows with `auto_mode` on are touched. Everything else is the operator's to drive.
 */
class CheckAutoModeShows extends Command
{
    protected $signature = 'shows:check-auto-mode';

    protected $description = 'Start auto mode shows when their source comes online, and stop them at their hard stop time';

    public function handle(): int
    {
        $this->checkShowsToStart();
        $this->checkShowsToEnd();

        return self::SUCCESS;
    }

    /**
     * Scheduled, auto mode, start time passed, source online.
     */
    private function checkShowsToStart(): void
    {
        $shows = Show::query()
            ->where('auto_mode', true)
            ->where('status', 'scheduled')
            ->where('scheduled_start', '<=', now())
            ->whereHas('source', fn ($query) => $query->where('status', SourceStatusEnum::ONLINE))
            ->get();

        foreach ($shows as $show) {
            Log::info('Auto mode: starting show', [
                'show_id' => $show->id,
                'show_title' => $show->title,
                'scheduled_start' => $show->scheduled_start?->toIso8601String(),
                'source_status' => $show->source?->status?->value,
            ]);

            $show->goLive();

            $this->info("Started '{$show->title}'");
        }
    }

    /**
     * Live, auto mode, hard stop reached.
     *
     * The hard stop is filtered in PHP rather than SQL because it is `auto_stop_at` with a
     * fallback to `scheduled_end`, and expressing that as a COALESCE would tie this to one
     * database's date handling. The candidate set is only the live auto-mode shows, so it
     * is a handful of rows at most.
     */
    private function checkShowsToEnd(): void
    {
        $shows = Show::query()
            ->where('auto_mode', true)
            ->where('status', 'live')
            ->get()
            ->filter(fn (Show $show) => $show->isPastAutoStop());

        foreach ($shows as $show) {
            Log::info('Auto mode: hard stop reached, ending show', [
                'show_id' => $show->id,
                'show_title' => $show->title,
                'hard_stop' => $show->autoStopAt()?->toIso8601String(),
                // Recorded because an explicit hard stop that is not the scheduled end is
                // the case worth being able to explain after the fact.
                'explicit_hard_stop' => $show->auto_stop_at !== null,
                'source_status' => $show->source?->status?->value,
            ]);

            $show->endLivestream();

            $this->info("Ended '{$show->title}' (hard stop reached)");
        }
    }
}
