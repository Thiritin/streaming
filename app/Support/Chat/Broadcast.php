<?php

namespace App\Support\Chat;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Fire-and-forget broadcasting for chat.
 *
 * Chat events are `ShouldBroadcastNow`, so a websocket server that is down or
 * unreachable throws straight into the request. The database write has already
 * happened by then, so failing the whole request would lose a message that was
 * in fact saved. Realtime delivery is best-effort: log it and carry on.
 */
class Broadcast
{
    public static function send(object $event): void
    {
        try {
            broadcast($event);
        } catch (Throwable $e) {
            Log::warning('Chat broadcast failed, event dropped', [
                'event' => $event::class,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
