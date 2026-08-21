<?php

namespace App\Jobs\Telegram;

use App\Models\FeedbackReport;
use App\Models\Recording;
use App\Models\Show;
use App\Models\TelegramMessage;
use App\Services\Telegram\TelegramNotifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Bring the messages already posted about one show or one report back in line with it.
 *
 * Dispatched from wherever the subject changed - the panel, a control surface, auto
 * mode, the chat itself - so a group is never left holding a Start button for a show
 * that somebody started in the control room ten minutes ago.
 */
class SyncTelegramMessagesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(public string $kind, public int $subjectId) {}

    public function handle(TelegramNotifier $notifier): void
    {
        if ($this->kind === TelegramMessage::KIND_SHOW) {
            $show = Show::with('source')->find($this->subjectId);

            if ($show) {
                $notifier->syncShow($show);
            }

            return;
        }

        if ($this->kind === TelegramMessage::KIND_RECORDING) {
            $recording = Recording::with(['show', 'source'])->find($this->subjectId);

            if ($recording) {
                $notifier->syncRecording($recording);
            }

            return;
        }

        $report = FeedbackReport::with(['user', 'show', 'source', 'handler'])->find($this->subjectId);

        if ($report) {
            $notifier->syncFeedback($report);
        }
    }
}
