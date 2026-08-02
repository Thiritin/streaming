<?php

namespace App\Http\Controllers\Chat;

use App\Http\Controllers\Controller;
use App\Models\ChatBan;
use App\Models\Timeout;
use App\Models\User;
use App\Services\Chat\ChatModerationService;
use App\Services\Chat\ChatSettingsService;
use App\Services\Chat\MessagePresenter;
use App\Services\ChatMessageSanitizer;
use Illuminate\Http\Request;

/**
 * Backs the mod menu and the per-message quick actions. Slash commands hit the same
 * service, so both paths behave identically.
 */
class ModerationController extends Controller
{
    public function __construct(
        protected ChatModerationService $moderation,
        protected ChatSettingsService $settings,
        protected MessagePresenter $presenter,
    ) {}

    public function timeout(Request $request)
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'seconds' => ['required', 'integer', 'min:1', 'max:1209600'],
            'reason' => ['nullable', 'string', 'max:200'],
            'source_id' => ['nullable', 'integer', 'exists:sources,id'],
        ]);

        $target = User::findOrFail($data['user_id']);

        $this->moderation->timeout(
            $request->user(),
            $target,
            $data['seconds'],
            $data['reason'] ?? null,
            $data['source_id'] ?? null,
        );

        return response()->json([
            'success' => true,
            'message' => "{$target->name} timed out for ".$this->moderation->humanizeSeconds($data['seconds']).'.',
        ]);
    }

    public function untimeout(Request $request)
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'source_id' => ['nullable', 'integer', 'exists:sources,id'],
        ]);

        $target = User::findOrFail($data['user_id']);
        $this->moderation->removeTimeout($request->user(), $target, $data['source_id'] ?? null);

        return response()->json(['success' => true, 'message' => "Timeout removed for {$target->name}."]);
    }

    public function ban(Request $request)
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'reason' => ['nullable', 'string', 'max:200'],
            'seconds' => ['nullable', 'integer', 'min:60'],
            'source_id' => ['nullable', 'integer', 'exists:sources,id'],
        ]);

        $target = User::findOrFail($data['user_id']);

        $this->moderation->ban(
            $request->user(),
            $target,
            $data['reason'] ?? null,
            isset($data['seconds']) ? now()->addSeconds($data['seconds']) : null,
            $data['source_id'] ?? null,
        );

        return response()->json(['success' => true, 'message' => "{$target->name} banned from chat."]);
    }

    public function unban(Request $request)
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'source_id' => ['nullable', 'integer', 'exists:sources,id'],
        ]);

        $target = User::findOrFail($data['user_id']);
        $this->moderation->unban($request->user(), $target, $data['source_id'] ?? null);

        return response()->json(['success' => true, 'message' => "{$target->name} unbanned."]);
    }

    public function purge(Request $request)
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'source_id' => ['nullable', 'integer', 'exists:sources,id'],
            'within_seconds' => ['nullable', 'integer', 'min:1'],
        ]);

        $target = User::findOrFail($data['user_id']);

        $count = $this->moderation->purgeUser(
            $request->user(),
            $target,
            $data['source_id'] ?? null,
            $data['within_seconds'] ?? null,
        );

        return response()->json([
            'success' => true,
            'message' => $count === 0
                ? "No messages from {$target->name} to remove."
                : "Removed {$count} message".($count === 1 ? '' : 's')." from {$target->name}.",
        ]);
    }

    public function clear(Request $request)
    {
        $data = $request->validate([
            'source_id' => ['nullable', 'integer', 'exists:sources,id'],
        ]);

        $count = $this->moderation->clearChat($request->user(), $data['source_id'] ?? null);

        return response()->json(['success' => true, 'message' => "Cleared {$count} messages."]);
    }

    public function announce(Request $request)
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'min:1', 'max:500'],
            'source_id' => ['nullable', 'integer', 'exists:sources,id'],
        ]);

        $body = (new ChatMessageSanitizer)->sanitize($data['message']);
        $message = $this->moderation->announce($request->user(), $body, $data['source_id'] ?? null);

        return response()->json(['success' => true, 'message' => 'Announcement sent.', 'announcement' => $this->presenter->present($message)]);
    }

    public function updateSettings(Request $request)
    {
        $data = $request->validate([
            'source_id' => ['nullable', 'integer', 'exists:sources,id'],
            'slow_mode_seconds' => ['nullable', 'integer', 'min:0', 'max:300'],
            'emote_only' => ['nullable', 'boolean'],
            'sponsors_only' => ['nullable', 'boolean'],
        ]);

        $sourceId = $data['source_id'] ?? null;
        unset($data['source_id']);

        return response()->json([
            'success' => true,
            'settings' => $this->moderation->updateSettings($request->user(), $data, $sourceId),
        ]);
    }

    /**
     * Everything the mod menu shows: who is timed out, who is banned.
     */
    public function index(Request $request)
    {
        abort_unless($request->user()->canModerateChat(), 403);

        return response()->json([
            'timeouts' => Timeout::with('user:id,name', 'issuedBy:id,name')
                ->active()
                ->latest('expires_at')
                ->limit(50)
                ->get()
                ->map(fn (Timeout $timeout) => [
                    'user_id' => $timeout->user_id,
                    'name' => $timeout->user?->name,
                    'expires_at' => $timeout->expires_at->toIso8601String(),
                    'seconds_remaining' => (int) now()->diffInSeconds($timeout->expires_at),
                    'reason' => $timeout->reason,
                    'issued_by' => $timeout->issuedBy?->name,
                ]),
            'bans' => ChatBan::with('user:id,name', 'bannedBy:id,name')
                ->active()
                ->latest('id')
                ->limit(50)
                ->get()
                ->map(fn (ChatBan $ban) => [
                    'user_id' => $ban->user_id,
                    'name' => $ban->user?->name,
                    'reason' => $ban->reason,
                    'permanent' => $ban->isPermanent(),
                    'expires_at' => $ban->expires_at?->toIso8601String(),
                    'issued_by' => $ban->bannedBy?->name,
                ]),
        ]);
    }
}
