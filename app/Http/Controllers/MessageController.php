<?php

namespace App\Http\Controllers;

use App\Events\Chat\Broadcasts\ChatMessageEvent;
use App\Http\Requests\MessageRequest;
use App\Models\Message;
use App\Models\User;
use App\Services\Chat\ChatModerationService;
use App\Services\Chat\ChatSettingsService;
use App\Services\Chat\MessagePresenter;
use App\Services\ChatMessageSanitizer;
use App\Services\EmoteService;
use App\Support\Chat\Broadcast;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class MessageController extends Controller
{
    public function __construct(
        protected ChatSettingsService $settings,
        protected ChatModerationService $moderation,
        protected MessagePresenter $presenter,
        protected EmoteService $emotes,
    ) {}

    public function send(MessageRequest $request)
    {
        /** @var User $user */
        $user = $request->user();
        $sourceId = (int) $request->post('source_id');
        $settings = $this->settings->all($sourceId);

        if ($blocked = $this->blockedResponse($user)) {
            return $blocked;
        }

        if ($settings['sponsors_only'] && ! $this->canBypassModes($user) && ! $user->hasAnyRole(['sponsor', 'supersponsor'])) {
            return $this->refuse('sponsors_only', 'Chat is in sponsors-only mode right now.');
        }

        $sanitizer = new ChatMessageSanitizer;
        $body = $sanitizer->sanitize((string) $request->post('message'), $user);

        if ($sanitizer->isEffectivelyEmpty($body)) {
            return $this->refuse('empty_message', 'Your message is empty.');
        }

        if ($settings['emote_only'] && ! $this->canBypassModes($user) && ! $this->isEmoteOnly($body, $user)) {
            return $this->refuse('emote_only', 'Chat is in emote-only mode right now.');
        }

        if ($limited = $this->enforceRateLimit($user, $sourceId, $settings)) {
            return $limited;
        }

        $this->emotes->recordUsage($body, $user);

        $message = $user->messages()->create([
            'message' => $body,
            'source_id' => $sourceId,
            'reply_to_id' => $this->resolveReplyTo($request, $sourceId),
            'is_command' => false,
            'type' => 'user',
        ]);

        // Broadcast to everyone, including the sender: clients dedupe on message id,
        // which keeps ordering identical for the author and everyone else.
        Broadcast::send(new ChatMessageEvent($message));

        return response([
            'success' => true,
            'message' => $this->presenter->present($message),
            'limits' => $this->limits($user, $sourceId, $settings),
        ]);
    }

    public function loadOlder(Request $request)
    {
        $request->validate([
            'source_id' => ['required', 'integer', 'exists:sources,id'],
            'before_id' => ['nullable', 'integer'],
        ]);

        $user = $request->user();
        $limit = (int) config('chat.history.page', 50);

        $query = Message::with(['user', 'replyTo.user'])
            ->visibleTo($user)
            ->where('source_id', $request->integer('source_id'));

        if ($beforeId = $request->integer('before_id')) {
            $query->where('id', '<', $beforeId);
        }

        $messages = $query->orderByDesc('id')->limit($limit)->get()->reverse();

        return response()->json([
            'messages' => $this->presenter->presentMany($messages),
            'hasMore' => $messages->count() === $limit,
        ]);
    }

    /**
     * Delete a single message: the author's own, or anyone's for moderators.
     */
    public function destroy(Request $request, Message $message)
    {
        $this->moderation->deleteMessage($request->user(), $message);

        return response()->json(['success' => true]);
    }

    /**
     * Refuse to send, with a machine-readable reason the client can react to.
     */
    protected function refuse(string $error, string $message, int $status = Response::HTTP_FORBIDDEN)
    {
        return response(['success' => false, 'error' => $error, 'message' => $message], $status);
    }

    /**
     * Bans and timeouts silence a user everywhere.
     */
    protected function blockedResponse(User $user)
    {
        if ($ban = $user->activeChatBan()) {
            return $this->refuse('user_banned', $ban->isPermanent()
                ? 'You are banned from chat.'.($ban->reason ? " Reason: {$ban->reason}" : '')
                : 'You are banned from chat until '.$ban->expires_at->format('H:i').'.');
        }

        if ($timeout = $user->activeTimeout()) {
            $remaining = (int) now()->diffInSeconds($timeout->expires_at);

            return response([
                'success' => false,
                'error' => 'user_timed_out',
                'message' => "You are timed out for {$remaining} more seconds"
                    .($timeout->reason ? " (Reason: {$timeout->reason})" : ''),
                'timeout' => [
                    'expires_at' => $timeout->expires_at,
                    'remaining_seconds' => $remaining,
                    'reason' => $timeout->reason,
                ],
            ], Response::HTTP_FORBIDDEN);
        }

        return null;
    }

    /**
     * Slow mode allows one message per interval; otherwise the burst limiter applies.
     *
     * @param  array<string, mixed>  $settings
     */
    protected function enforceRateLimit(User $user, int $sourceId, array $settings)
    {
        if ($this->canBypassModes($user)) {
            return null;
        }

        $key = $this->rateKey($user, $sourceId);
        $slowMode = (int) $settings['slow_mode_seconds'];
        $maxTries = $slowMode > 0 ? 1 : (int) $settings['max_tries'];
        $decay = $slowMode > 0 ? $slowMode : (int) $settings['rate_decay'];

        if (RateLimiter::tooManyAttempts($key, $maxTries)) {
            $seconds = RateLimiter::availableIn($key);

            return response([
                'success' => false,
                'error' => 'rate_limit_hit',
                'message' => $slowMode > 0
                    ? "Slow mode is on. You can chat again in {$seconds}s."
                    : "You are sending messages too quickly. Try again in {$seconds}s.",
                'limits' => array_merge($this->limits($user, $sourceId, $settings), ['seconds_left' => $seconds]),
            ], Response::HTTP_TOO_MANY_REQUESTS);
        }

        RateLimiter::hit($key, $decay);

        return null;
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    protected function limits(User $user, int $sourceId, array $settings): array
    {
        $slowMode = (int) $settings['slow_mode_seconds'];
        $bypass = $this->canBypassModes($user);

        return [
            'slow_mode_seconds' => $slowMode,
            'max_tries' => $slowMode > 0 ? 1 : (int) $settings['max_tries'],
            'rate_decay' => $slowMode > 0 ? $slowMode : (int) $settings['rate_decay'],
            'seconds_left' => $bypass ? 0 : RateLimiter::availableIn($this->rateKey($user, $sourceId)),
            'can_bypass' => $bypass,
        ];
    }

    protected function rateKey(User $user, int $sourceId): string
    {
        return "send-message:{$user->id}:{$sourceId}";
    }

    protected function canBypassModes(User $user): bool
    {
        return $user->hasPermission('chat.ignore.ratelimit') || $user->canModerateChat();
    }

    /**
     * True when a message is nothing but emotes the user can actually send.
     */
    protected function isEmoteOnly(string $body, User $user): bool
    {
        $available = $this->emotes->getAvailableEmotes($user);

        $stripped = preg_replace_callback(
            '/:([a-z0-9_]+):/i',
            fn (array $matches) => isset($available[strtolower($matches[1])]) ? '' : $matches[0],
            $body,
        );

        return trim($stripped) === '';
    }

    protected function resolveReplyTo(Request $request, int $sourceId): ?int
    {
        $replyToId = $request->integer('reply_to_id');

        if (! $replyToId) {
            return null;
        }

        return Message::where('id', $replyToId)->where('source_id', $sourceId)->value('id');
    }
}
