<?php

namespace App\Jobs\Telegram;

use App\Models\Recording;
use App\Services\Telegram\TelegramNotifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Announce a recording that has just appeared - a cut from a show, an import, or one
 * created by hand. The same message is later rewritten when it is published.
 */
class SendRecordingAlertJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(public int $recordingId) {}

    public function handle(TelegramNotifier $notifier): void
    {
        $recording = Recording::with(['show', 'source'])->find($this->recordingId);

        if ($recording) {
            $notifier->recordingCreated($recording);
        }
    }
}
