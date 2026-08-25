<?php

namespace App\Jobs\Telegram;

use App\Models\RecordingComment;
use App\Services\Telegram\TelegramNotifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Announce a comment a report has just taken down.
 *
 * Queued because it hangs off the reporting viewer's request: pressing Report has
 * already hidden the comment, and nobody should wait on the Telegram API to be told
 * that it worked.
 */
class SendCommentReportAlertJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(public int $commentId) {}

    public function handle(TelegramNotifier $notifier): void
    {
        $comment = RecordingComment::with(['user', 'recording', 'approver'])->find($this->commentId);

        if ($comment) {
            $notifier->commentReported($comment);
        }
    }
}
