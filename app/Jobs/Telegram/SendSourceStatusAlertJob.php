<?php

namespace App\Jobs\Telegram;

use App\Models\Source;
use App\Services\Telegram\TelegramNotifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * A source changing state, posted as a log line.
 *
 * Unlike a show or a recording this is not a subject with a life of its own: it is a
 * moment, so it is posted and never edited. What keeps a flapping encoder from filling
 * the chat is the notifier, which only posts when the state differs from the one it last
 * announced.
 */
class SendSourceStatusAlertJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(public int $sourceId, public ?string $previousStatus = null) {}

    public function handle(TelegramNotifier $notifier): void
    {
        $source = Source::find($this->sourceId);

        if ($source) {
            $notifier->sourceStatusChanged($source, $this->previousStatus);
        }
    }
}
