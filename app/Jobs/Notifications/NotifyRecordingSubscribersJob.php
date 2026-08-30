<?php

namespace App\Jobs\Notifications;

use App\Models\Recording;
use App\Services\ViewerNotifier;
use App\Support\Features;
use App\Support\NotificationSettings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * One recording's audience. Split off the scan so a recording nobody can be told about
 * cannot take the rest of the batch down with it.
 */
class NotifyRecordingSubscribersJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly int $recordingId) {}

    public function uniqueId(): string
    {
        return (string) $this->recordingId;
    }

    public function handle(ViewerNotifier $notifier): void
    {
        if (! Features::enabled('notifications')) {
            return;
        }

        $recording = Recording::with(['source', 'show', 'category'])->find($this->recordingId);

        // Checked again rather than trusted from the scan: minutes may have passed in
        // the queue, and the whole point of the delay is that it is still running when
        // somebody hits unpublish.
        if (! $recording || ! $recording->is_published || $recording->notified_at) {
            return;
        }

        if (! $recording->published_at || $recording->published_at->gt(now()->subHours(NotificationSettings::delayHours()))) {
            return;
        }

        // Stamped before the sends rather than after: a crash halfway through must not
        // start the whole audience again from the top, and the per-viewer claims mean
        // anybody already written to is skipped when the scan comes back round.
        $recording->forceFill(['notified_at' => now()])->saveQuietly();

        $notifier->recordingPublished($recording);
    }
}
