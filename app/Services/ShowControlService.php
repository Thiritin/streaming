<?php

namespace App\Services;

use App\Models\Show;
use App\Models\Source;
use Illuminate\Support\Facades\Log;

/**
 * What the Play and Stop buttons on a control surface do.
 *
 * The surface has no show picker: it has one source and two buttons, so the whole
 * decision of *which* show a press applies to lives here. Both actions go through
 * `Show::goLive()` / `Show::endLivestream()`, the same methods /manage calls, so the
 * events, timestamps and viewer notifications are identical either way.
 */
class ShowControlService
{
    /**
     * The show a Play press would start, and why it was picked.
     *
     * Two rules, in order:
     *
     * 1. A scheduled show whose slot contains right now. This is the common case: the
     *    slot has begun and someone is late pressing Play.
     * 2. Otherwise the next scheduled show, however far out. Pressing Play early is a
     *    deliberate act ("we are ready, go"), so it is allowed rather than refused.
     *
     * A scheduled show whose slot has already ended is skipped: it was missed, and
     * starting it would put the wrong title on air. Rule 2 moves past it.
     *
     * @return array{show: Show|null, reason: string}
     */
    public function nextUp(Source $source): array
    {
        $now = now();

        $current = $source->shows()
            ->where('status', 'scheduled')
            ->where('scheduled_start', '<=', $now)
            ->where('scheduled_end', '>=', $now)
            ->orderBy('scheduled_start')
            ->first();

        if ($current) {
            return ['show' => $current, 'reason' => 'current'];
        }

        $next = $source->shows()
            ->where('status', 'scheduled')
            ->where('scheduled_start', '>', $now)
            ->orderBy('scheduled_start')
            ->first();

        return ['show' => $next, 'reason' => $next ? 'next' : 'none'];
    }

    /**
     * The show currently on air on this source. Newest first, so a double-start that
     * somehow left two live rows still stops the one that is actually being watched.
     */
    public function liveShow(Source $source): ?Show
    {
        return $source->shows()
            ->where('status', 'live')
            ->orderByDesc('actual_start')
            ->first();
    }

    /**
     * Start whatever Play means right now. Idempotent: pressing it again while a show is
     * live changes nothing, so a stuck button or a double tap cannot end up stacking shows.
     *
     * @return array{ok: bool, action: string, message: string, show: Show|null}
     */
    public function start(Source $source): array
    {
        $live = $this->liveShow($source);

        if ($live) {
            return [
                'ok' => true,
                'action' => 'none',
                'message' => "'{$live->title}' is already live.",
                'show' => $live,
            ];
        }

        ['show' => $show, 'reason' => $reason] = $this->nextUp($source);

        if (! $show) {
            return [
                'ok' => false,
                'action' => 'none',
                'message' => 'No scheduled show is queued on this source.',
                'show' => null,
            ];
        }

        $show->goLive();

        Log::info('Control surface started a show', [
            'source_id' => $source->id,
            'show_id' => $show->id,
            'show_title' => $show->title,
            'reason' => $reason,
            'scheduled_start' => $show->scheduled_start?->toIso8601String(),
        ]);

        return [
            'ok' => true,
            'action' => $reason === 'current' ? 'started_current' : 'started_next',
            'message' => "'{$show->title}' is now live.",
            'show' => $show->refresh(),
        ];
    }

    /**
     * End the show on air. Also idempotent: nothing live is a no-op, not an error, so a
     * Stop press after an auto-mode hard stop does not light the button up red.
     *
     * @return array{ok: bool, action: string, message: string, show: Show|null}
     */
    public function stop(Source $source): array
    {
        $live = $this->liveShow($source);

        if (! $live) {
            return [
                'ok' => true,
                'action' => 'none',
                'message' => 'Nothing is live on this source.',
                'show' => null,
            ];
        }

        $live->endLivestream();

        Log::info('Control surface ended a show', [
            'source_id' => $source->id,
            'show_id' => $live->id,
            'show_title' => $live->title,
        ]);

        return [
            'ok' => true,
            'action' => 'stopped',
            'message' => "'{$live->title}' has ended.",
            'show' => $live->refresh(),
        ];
    }
}
