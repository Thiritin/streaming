<?php

namespace App\Http\Controllers;

use App\Enum\ServerStatusEnum;
use App\Enum\StreamStatusEnum;
use App\Events\UserWaitingForProvisioningEvent;
use App\Models\Client;
use App\Models\Message;
use App\Models\Server;
use App\Models\ServerUser;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Inertia;

class StreamController extends Controller
{
    public function online()
    {
        /** @var User $user */
        $user = Auth::user();
        $data = $user->getUserStreamUrls();
        $urls = $data['urls'];
        $client_id = $data['client_id'];

        return Inertia::render('Dashboard', [
            'initialProvisioning' => is_null($data['urls']),
            'initialClientId' => $client_id,
            'initialStreamUrls' => $urls,
            'initialStatus' => \Cache::get('stream.status', static fn() => StreamStatusEnum::OFFLINE->value),
            'initialListeners' => \Cache::get('stream.listeners', static fn() => 0),
            'chatMessages' => array_values(Message::with('user')
                ->where('is_command', false)
                ->orWhere(fn($q) => $q->where('is_command', true)->where('user_id', $user->id)) // show users own commands
                ->orderBy('created_at', 'desc')
                ->limit(50)
                ->get()
                ->reverse()
                ->map(fn(Message $message) => [
                    'id' => $message->id,
                    'message' => $message->message,
                    'is_command' => (bool) $message->is_command,
                    'name' => $message->user->name ?? "System",
                    'role' => $message->user?->role,
                    'time' => $message->created_at->format('H:i'),
                ])->toArray()),
            'rateLimit' => [
                'maxTries' => \Cache::get('chat.maxTries', static fn() => config('chat.default.maxTries')),
                'rateDecay' => \Cache::get('chat.rateDecay', static fn() => config('chat.default.rateDecay')),
                'slowMode' => \Cache::get('chat.slowMode', static fn() => config('chat.default.slowMode')),
                'secondsLeft' => (!$user->isStaff()) ? RateLimiter::availableIn('send-message:' . $user->id) : 0,
            ],
        ]);
    }

    public function external()
    {
        $urls = Auth::user()->getUserStreamUrls()['urls'];
        return Inertia::render('ExternalStream', [
            'status' => \Cache::get('stream.status', static fn() => StreamStatusEnum::OFFLINE->value),
            'streamUrls' => $urls,
        ]);
    }


}
