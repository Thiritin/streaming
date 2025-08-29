<?php

namespace App\Http\Controllers;

use App\Events\Chat\Broadcasts\ChatMessageEvent;
use App\Http\Requests\MessageRequest;
use App\Jobs\ChatCommands\NotFoundJob;
use App\Services\ChatCommandService;
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
        $maxTries = Cache::get('chat.maxTries', static fn() => config('chat.default.maxTries'));
        $rateDecay = Cache::get('chat.rateDecay', static fn() => config('chat.default.rateDecay'));
        $slowMode = Cache::get('chat.slowMode', static fn() => config('chat.default.slowMode'));

        if (!is_null($user->timeout_expires_at) && $user->timeout_expires_at->isFuture()) {
            throw ValidationException::withMessages([
               "message" => "You are in timeout until {$user->timeout_expires_at->format('H:i:s')}"
            ]);
        }

        if ($user->cant('chat.ignore.ratelimit')) {
            if (RateLimiter::tooManyAttempts('send-message:' . $user->id, $maxTries)) {
                $seconds = RateLimiter::availableIn('send-message:' . $user->id);
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
            RateLimiter::hit('send-message:' . $user->id, (int) $rateDecay);
        }
        
        // Check if message is command
        $commandService = new ChatCommandService();
        $isCommand = $commandService->isCommand($message);
        
        // Sanitize message if it's not a command
        if (!$isCommand) {
            $sanitizer = new ChatMessageSanitizer();
            $message = $sanitizer->sanitize($message);
        }

        $messageModel = $user->messages()->create([
            'message' => $message,
            'is_command' => $isCommand,
        ]);

        $this->processCommand($user, $messageModel);

        if (!$isCommand) {
            broadcast(new ChatMessageEvent($messageModel, $user))->toOthers();
        }



        return response([
            'success' => true,
            'rateLimit' => [
                'maxTries' => $maxTries,
                'secondsLeft' => $this->getSecondsLeft($user,$slowMode,$maxTries),
                'rateDecay' => $rateDecay,
                'slowMode' => $slowMode,
            ],
        ]);
    }

    private function processCommand(mixed $user, $messageModel)
    {
        if (!$messageModel->is_command) {
            return;
        }
        
        $commandService = new ChatCommandService();
        $commandName = $commandService->extractCommandName($messageModel->message);
        $commandClass = $commandService->getCommandClass($commandName);
        
        if ($commandClass === null) {
            $commandClass = NotFoundJob::class;
        }
        
        $commandClass::dispatch($user, $messageModel, trim($messageModel->message));
    }

    private function getSecondsLeft($user,$slowMode,$maxTries)
    {
        if($user->can('chat.ignore.ratelimit')) {
            return 0;
        }
        // If slow mode is active always return seconds left
        if ($slowMode) {
            return RateLimiter::availableIn('send-message:' . $user->id);
        }

        // In any other case only return if the rate limiter is now hit.
        if (RateLimiter::tooManyAttempts('send-message:' . $user->id, $maxTries)) {
            return RateLimiter::availableIn('send-message:' . $user->id);
        }
        return 0;
    }
}
