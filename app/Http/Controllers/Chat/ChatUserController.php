<?php

namespace App\Http\Controllers\Chat;

use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Models\User;
use App\Services\Chat\ChatModerationService;
use App\Services\Chat\MessagePresenter;
use Illuminate\Http\Request;

/**
 * Data behind the user card that opens when a chatter's name is clicked.
 */
class ChatUserController extends Controller
{
    public function __construct(
        protected MessagePresenter $presenter,
        protected ChatModerationService $moderation,
    ) {}

    public function show(Request $request, User $user)
    {
        $viewer = $request->user();
        $sourceId = $request->integer('source_id') ?: null;

        $payload = [
            'id' => $user->id,
            'name' => $user->name,
            'color' => $user->chat_color,
            'badges' => $user->chatBadges(),
            'member_since' => $user->created_at?->toIso8601String(),
            'is_self' => $viewer->id === $user->id,
            'can_moderate' => $this->moderation->canActOn($viewer, $user),
            'can_ban' => $viewer->canBanFromChat() && $this->moderation->canActOn($viewer, $user),
        ];

        if (! $viewer->canModerateChat()) {
            return response()->json($payload);
        }

        $messages = Message::with('user')
            ->where('user_id', $user->id)
            ->when($sourceId, fn ($query) => $query->where('source_id', $sourceId))
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        $timeout = $user->activeTimeout();
        $ban = $user->activeChatBan();

        return response()->json(array_merge($payload, [
            'message_count' => Message::where('user_id', $user->id)
                ->when($sourceId, fn ($query) => $query->where('source_id', $sourceId))
                ->count(),
            'recent_messages' => $this->presenter->presentMany($messages->reverse()),
            'timeout' => $timeout ? [
                'expires_at' => $timeout->expires_at->toIso8601String(),
                'seconds_remaining' => (int) now()->diffInSeconds($timeout->expires_at),
                'reason' => $timeout->reason,
            ] : null,
            'ban' => $ban ? [
                'permanent' => $ban->isPermanent(),
                'expires_at' => $ban->expires_at?->toIso8601String(),
                'reason' => $ban->reason,
            ] : null,
        ]));
    }
}
