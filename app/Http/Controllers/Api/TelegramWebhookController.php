<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Telegram\TelegramUpdateHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Where Telegram delivers updates.
 *
 * Always answers 200, whatever happened inside: an update Telegram considers undelivered
 * is retried, and retrying a button press that already started a show is worse than
 * dropping one. Failures are logged here instead.
 *
 * Handled inline rather than queued. A press has to change the message it came from
 * within a second or two to be believable, and the work is two API calls.
 */
class TelegramWebhookController extends Controller
{
    public function __invoke(Request $request, TelegramUpdateHandler $handler): JsonResponse
    {
        try {
            $handler->handle($request->all());
        } catch (\Throwable $e) {
            Log::error('Telegram update failed', [
                'error' => $e->getMessage(),
                'update_id' => $request->input('update_id'),
            ]);
        }

        return response()->json(['ok' => true]);
    }
}
