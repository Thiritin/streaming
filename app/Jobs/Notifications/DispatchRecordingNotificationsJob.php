<?php

namespace App\Jobs\Notifications;

use App\Models\Recording;
use App\Support\Features;
use App\Support\NotificationSettings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * The hand coming off the send button.
 *
 * Nothing is queued when a recording is published; it is picked up here once it has
 * been visible for the configured delay and nobody has taken it down again. That is
 * what makes the window mean something: a wrong cut, a wrong title or a thumbnail that
 * never captured can be fixed inside it and nobody was ever told about the mistake.
 *
 * Scanning rather than scheduling: a delayed job would have to be found and cancelled
 * when the recording is unpublished, and a queue that was down over the window would
 * lose the send outright.
 */
class DispatchRecordingNotificationsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        if (! Features::enabled('notifications')) {
            return;
        }

        $now = now();

        Recording::query()
            ->where('is_published', true)
            ->whereNull('notified_at')
            ->whereNotNull('published_at')
            ->where('published_at', '<=', $now->copy()->subHours(NotificationSettings::delayHours()))
            // A recording published long before anybody could have been waiting for it -
            // an archive imported in bulk, a queue that stopped for a fortnight - is not
            // news, and mailing it out is how a subscription list gets burned.
            ->where('published_at', '>=', $now->copy()->subDays(NotificationSettings::catchUpDays()))
            ->orderBy('published_at')
            ->limit(50)
            ->pluck('id')
            ->each(fn (int $id) => NotifyRecordingSubscribersJob::dispatch($id));
    }
}
