<?php

namespace App\Jobs\Telegram;

use App\Models\Show;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramNotifier;
use App\Support\TelegramSettings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * The every-minute scan for shows about to start.
 *
 * A show is announced once, a few minutes before its slot, and the notifier skips any
 * chat that already holds a message for it - so a scan that runs twice, or a worker
 * that retries, does not post the same show again.
 *
 * Only scheduled shows: one already live was started by somebody, and one that has
 * ended is history.
 */
class NotifyUpcomingShowsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 120;

    public function handle(TelegramClient $client, TelegramNotifier $notifier): void
    {
        if (! $client->enabled()) {
            return;
        }

        $lead = TelegramSettings::leadMinutes();

        $shows = Show::with('source')
            ->where('status', 'scheduled')
            ->whereNotNull('scheduled_start')
            ->whereBetween('scheduled_start', [now()->subMinutes($lead), now()->addMinutes($lead)])
            ->orderBy('scheduled_start')
            ->get();

        foreach ($shows as $show) {
            $notifier->upcomingShow($show);
        }
    }
}
