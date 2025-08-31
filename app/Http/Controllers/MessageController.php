<?php

namespace App\Http\Controllers;

use App\Events\Chat\Broadcasts\ChatMessageEvent;
use App\Http\Requests\MessageRequest;
use App\Models\Timeout;
use App\Services\ChatMessageSanitizer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response as SymphonyResponse;

class MessageController extends Controller
{
    public function send(MessageRequest $request)
    {
        $message = $request->post('message');
        $user = $request->user();
        $maxTries = Cache::get('chat.maxTries', static fn () => config('chat.default.maxTries'));
        $rateDecay = Cache::get('chat.rateDecay', static fn () => config('chat.default.rateDecay'));
        $slowMode = Cache::get('chat.slowMode', static fn () => config('chat.default.slowMode'));

        // Check if user is timed out
        $activeTimeout = Timeout::where('user_id', $user->id)
            ->where('expires_at', '>', now())
            ->first();
            
        if ($activeTimeout) {
            $remainingTime = now()->diffInSeconds($activeTimeout->expires_at);
            $message = "You are timed out for {$remainingTime} more seconds";
            if ($activeTimeout->reason) {
                $message .= " (Reason: {$activeTimeout->reason})";
            }
            
            return response([
                'success' => false,
                'error' => 'user_timed_out',
                'message' => $message,
                'timeout' => [
                    'expires_at' => $activeTimeout->expires_at,
                    'remaining_seconds' => $remainingTime,
                    'reason' => $activeTimeout->reason,
                ],
            ], SymphonyResponse::HTTP_FORBIDDEN);
        }

        if ($user->cant('chat.ignore.ratelimit') && !$user->isAdmin() && !$user->isModerator()) {
            if (RateLimiter::tooManyAttempts('send-message:'.$user->id, $maxTries)) {
                $seconds = RateLimiter::availableIn('send-message:'.$user->id);

                return response([
                    'success' => false,
                    'rateLimit' => [
                        'maxTries' => $maxTries,
                        'secondsLeft' => $seconds,
                        'rateDecay' => $rateDecay,
                        'slowMode' => $slowMode,
                    ],
                    'error' => 'rate_limit_hit',
                ], SymphonyResponse::HTTP_TOO_MANY_REQUESTS);
            }
            RateLimiter::hit('send-message:'.$user->id, (int) $rateDecay);
        }

        // Commands are now handled via separate API endpoint
        // This endpoint only handles regular messages
        if (str_starts_with(trim($message), '/')) {
            throw ValidationException::withMessages([
                'message' => 'Commands should be sent via the command API endpoint.',
            ]);
        }

        // Sanitize message
        $sanitizer = new ChatMessageSanitizer;
        $message = $sanitizer->sanitize($message, $user);

        $messageModel = $user->messages()->create([
            'message' => $message,
            'is_command' => false,
            'type' => 'user',
        ]);

        broadcast(new ChatMessageEvent($messageModel, $user))->toOthers();

        return response([
            'success' => true,
            'rateLimit' => [
                'maxTries' => $maxTries,
                'secondsLeft' => $this->getSecondsLeft($user, $slowMode, $maxTries),
                'rateDecay' => $rateDecay,
                'slowMode' => $slowMode,
            ],
        ]);
    }


    private function getSecondsLeft($user, $slowMode, $maxTries)
    {
        if ($user->can('chat.ignore.ratelimit') || $user->isAdmin() || $user->isModerator()) {
            return 0;
        }
        // If slow mode is active always return seconds left
        if ($slowMode) {
            return RateLimiter::availableIn('send-message:'.$user->id);
        }

        // In any other case only return if the rate limiter is now hit.
        if (RateLimiter::tooManyAttempts('send-message:'.$user->id, $maxTries)) {
            return RateLimiter::availableIn('send-message:'.$user->id);
        }

        return 0;
    }
}
