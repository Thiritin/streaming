<?php

namespace App\Jobs\Telegram;

use App\Models\TelegramChat;
use App\Services\Telegram\TelegramNotifier;
use App\Support\HealthAlertDigest;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * What the dashboard's alert list says, posted to the chats that asked for it.
 *
 * Scheduled every minute and recomputes current state rather than accumulating it, so a
 * queued copy that has not run yet already does everything a second one would.
 *
 * With nobody listening the state is dropped rather than tracked: a chat switched on at
 * three in the morning should be told what is wrong now, not that everything has been
 * quiet since the alert it never saw.
 */
class SendHealthAlertsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 300;

    public function handle(HealthAlertDigest $digest, TelegramNotifier $notifier): void
    {
        if (! TelegramChat::active()->where('notify_health', true)->exists()) {
            HealthAlertDigest::forget();

            return;
        }

        $changes = $digest->tick();

        $notifier->healthAlerts($changes['raised'], $changes['cleared']);
    }
}
