<?php

namespace App\Http\Controllers;

use App\Events\Chat\Broadcasts\ChatMessageEvent;
use App\Http\Requests\MessageRequest;
use App\Jobs\ChatCommands\BroadcastJob;
use App\Jobs\ChatCommands\DeleteMessageJob;
use App\Jobs\ChatCommands\NotFoundJob;
use App\Jobs\ChatCommands\SlowModeJob;
use App\Jobs\ChatCommands\TimeoutJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
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

        if (!$user->isStaff()) {
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
            RateLimiter::hit('send-message:' . $user->id, $rateDecay);
        }
        /**
         * Determine if message is command
         */
        $isCommand = false;
        if (Str::startsWith(trim($message), ['/', '!'])) {
            $isCommand = true;
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
                'secondsLeft' => (!$user->isStaff()) ? RateLimiter::availableIn('send-message:' . $user->id) : 0,
                'rateDecay' => $rateDecay,
                'slowMode' => $slowMode,
            ],
        ]);
    }

    private function processCommand(mixed $user, $messageModel)
    {
        /**
         * Determine which kind of Command it is
         */
        $command = $messageModel->message;
        $trimmedCommand = trim($command);
        $command = Str::substr($command, 1);
        $args = explode(' ', $command);
        $command = $args[0];
        $runController = match ($command) {
            "timeout" => TimeoutJob::class,
            "delete" => DeleteMessageJob::class,
            "broadcast" => BroadcastJob::class,
            "slowmode" => SlowModeJob::class,
            "slow" => SlowModeJob::class,
            default => NotFoundJob::class,
        };

        $runController::dispatch($user, $messageModel, $trimmedCommand);
    }
}