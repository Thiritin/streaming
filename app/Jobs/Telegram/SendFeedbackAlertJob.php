<?php

namespace App\Jobs\Telegram;

use App\Models\FeedbackReport;
use App\Services\Telegram\TelegramNotifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Announce a report in every chat that asked for them.
 *
 * Queued because it hangs off a viewer's request: somebody whose stream is already
 * broken should not also wait on the Telegram API to be told their report went through.
 */
class SendFeedbackAlertJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(public int $reportId) {}

    public function handle(TelegramNotifier $notifier): void
    {
        $report = FeedbackReport::with(['user', 'show', 'source'])->find($this->reportId);

        if ($report) {
            $notifier->feedbackCreated($report);
        }
    }
}
