<?php

namespace App\Jobs\Notifications;

use App\Models\Show;
use App\Services\ViewerNotifier;
use App\Support\Features;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * A followed show has gone live. No delay: an hour late is worthless.
 */
class NotifyShowSubscribersJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly int $showId) {}

    public function uniqueId(): string
    {
        return (string) $this->showId;
    }

    public function handle(ViewerNotifier $notifier): void
    {
        if (! Features::enabled('notifications')) {
            return;
        }

        $show = Show::with('source')->find($this->showId);

        // A show started and ended again while this sat in the queue is not live, and
        // sending "watch now" for it is worse than sending nothing.
        if (! $show || $show->status !== 'live') {
            return;
        }

        $notifier->showStarted($show);
    }
}
