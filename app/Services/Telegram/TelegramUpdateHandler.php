<?php

namespace App\Services\Telegram;

use App\Models\FeedbackReport;
use App\Models\Show;
use App\Models\TelegramChat;
use App\Models\TelegramLinkCode;
use App\Models\TelegramMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Everything that arrives from Telegram: the handful of commands the bot answers, and
 * the buttons it put in a chat itself.
 *
 * The trust model is the chat, not the person. A chat exists here because somebody with
 * the panel open minted a code and somebody in that room pasted it, and it has buttons
 * because an operator afterwards said it should. So a press is authorised by the row -
 * `enabled` and `interactive` - and the presser's handle is only ever recorded, never
 * checked. Anyone who can read an interactive group can press what is in it, which is
 * the same trust an unlocked control surface in a control room has.
 */
class TelegramUpdateHandler
{
    public function __construct(
        private readonly TelegramClient $client,
        private readonly TelegramNotifier $notifier,
    ) {}

    /**
     * @param  array<string, mixed>  $update
     */
    public function handle(array $update): void
    {
        if (isset($update['callback_query'])) {
            $this->callback($update['callback_query']);

            return;
        }

        if (isset($update['my_chat_member'])) {
            $this->membership($update['my_chat_member']);

            return;
        }

        if (isset($update['message'])) {
            $this->message($update['message']);
        }
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function message(array $message): void
    {
        $text = trim((string) ($message['text'] ?? ''));
        $chatId = (string) ($message['chat']['id'] ?? '');

        if ($chatId === '' || ! str_starts_with($text, '/')) {
            return;
        }

        // "/link@stream_bot CODE" in a group: Telegram appends the bot name whenever
        // more than one bot might answer.
        $parts = preg_split('/\s+/', $text) ?: [];
        $command = mb_strtolower(explode('@', ltrim((string) array_shift($parts), '/'))[0]);
        $argument = trim(implode(' ', $parts));

        $chat = TelegramChat::where('chat_id', $chatId)->first();

        match ($command) {
            'link' => $this->link($message, $argument),
            'unlink' => $this->unlink($chatId, $chat),
            'status' => $this->status($chatId, $chat),
            'chatid' => $this->client->reply($chatId, 'Chat id: <code>'.e($chatId).'</code>'),
            'start', 'help' => $this->client->reply($chatId, $this->help()),
            default => null,
        };
    }

    /**
     * `/link CODE`. The code is minted in /manage and pasted here, which is the only
     * way the bot can learn that this particular group is one of ours.
     *
     * @param  array<string, mixed>  $message
     */
    private function link(array $message, string $argument): void
    {
        $chatId = (string) $message['chat']['id'];
        $code = mb_strtoupper(trim($argument));

        if ($code === '') {
            $this->client->reply($chatId, 'Send <code>/link CODE</code> with the code from Settings > Telegram.');

            return;
        }

        $link = TelegramLinkCode::where('code', $code)->first();

        if (! $link || ! $link->usable()) {
            $this->client->reply($chatId, '❌ That code is not valid any more. Generate a new one in Settings > Telegram.');

            return;
        }

        $existing = TelegramChat::where('chat_id', $chatId)->first();

        if ($existing) {
            $existing->forceFill([
                'title' => $this->title($message['chat']),
                'type' => $message['chat']['type'] ?? null,
                'enabled' => true,
                'last_error' => null,
            ])->save();

            $link->forceFill(['used_at' => now(), 'telegram_chat_id' => $existing->id])->save();

            $this->client->reply($chatId, "✅ Already linked.\n".e($this->notifier->summary($existing)));

            return;
        }

        // Deliberately empty: linked, enabled, and told nothing. What a chat hears and
        // whether it gets buttons is a decision made in the panel by somebody who can
        // see which room this is, not by whoever happened to paste the code.
        $chat = TelegramChat::create([
            'chat_id' => $chatId,
            'title' => $this->title($message['chat']),
            'type' => $message['chat']['type'] ?? null,
            'enabled' => true,
            'interactive' => false,
            'notify_feedback' => false,
            'notify_shows' => false,
            'linked_by' => $link->created_by,
            'linked_at' => now(),
        ]);

        $link->forceFill(['used_at' => now(), 'telegram_chat_id' => $chat->id])->save();

        $this->client->reply(
            $chatId,
            "✅ <b>Linked.</b>\nNothing is switched on yet. Pick what this chat should get - shows, feedback, and whether it may press buttons - in /manage > Telegram.",
        );

        Log::info('Telegram chat linked', ['chat_id' => $chatId, 'code' => $code]);
    }

    private function unlink(string $chatId, ?TelegramChat $chat): void
    {
        if (! $chat) {
            $this->client->reply($chatId, 'This chat is not linked.');

            return;
        }

        $chat->delete();

        $this->client->reply($chatId, '👋 Unlinked. Nothing more will be posted here until a new code is used.');
    }

    private function status(string $chatId, ?TelegramChat $chat): void
    {
        $this->client->reply($chatId, $chat
            ? e($this->notifier->summary($chat)).($chat->enabled ? '' : "\n⚠️ Switched off in the panel.")
            : 'This chat is not linked. Send <code>/link CODE</code> with a code from Settings > Telegram.');
    }

    private function help(): string
    {
        return implode("\n", [
            '<b>Stream bot</b>',
            '/link CODE - link this chat, using a code from the panel',
            '/status - what this chat is set to receive',
            '/chatid - this chat\'s id, for adding it by hand',
            '/unlink - stop posting here',
        ]);
    }

    /**
     * A button press. Every path answers the callback query, because an unanswered one
     * spins in the client for a while and then looks like a bug.
     *
     * @param  array<string, mixed>  $query
     */
    private function callback(array $query): void
    {
        $callbackId = (string) ($query['id'] ?? '');
        $chatId = (string) ($query['message']['chat']['id'] ?? '');
        $messageId = (int) ($query['message']['message_id'] ?? 0);
        $data = (string) ($query['data'] ?? '');
        $actor = $this->actor($query['from'] ?? []);

        $chat = TelegramChat::where('chat_id', $chatId)->first();

        if (! $chat || ! $chat->enabled || ! $chat->interactive) {
            $this->client->answerCallback($callbackId, 'This chat is not allowed to do that.', alert: true);

            return;
        }

        [$kind, $action, $id] = array_pad(explode(':', $data, 3), 3, null);

        $record = TelegramMessage::where('telegram_chat_id', $chat->id)
            ->where('message_id', $messageId)
            ->first();

        match ($kind) {
            's' => $this->showAction($callbackId, $chat, $record, (string) $action, (int) $id, $actor),
            'f' => $this->feedbackAction($callbackId, (string) $action, (int) $id, $actor),
            default => $this->client->answerCallback($callbackId, 'Unknown button.'),
        };
    }

    private function showAction(
        string $callbackId,
        TelegramChat $chat,
        ?TelegramMessage $record,
        string $action,
        int $showId,
        string $actor,
    ): void {
        $show = Show::with('source')->find($showId);

        if (! $show) {
            $this->client->answerCallback($callbackId, 'That show is gone.', alert: true);

            return;
        }

        switch ($action) {
            case 'start':
                // Same lock as the control surface: two people in a group tapping Start
                // at once must not both find the show scheduled and both take it live.
                $started = DB::transaction(function () use ($show) {
                    $locked = Show::whereKey($show->getKey())->lockForUpdate()->first();

                    if (! $locked || $locked->status !== 'scheduled') {
                        return null;
                    }

                    $locked->goLive();

                    return $locked;
                });

                if (! $started) {
                    $this->client->answerCallback($callbackId, 'That show is not startable any more.', alert: true);
                    $this->notifier->syncShow($show->refresh());

                    return;
                }

                Log::info('Telegram started a show', [
                    'show_id' => $show->id,
                    'chat_id' => $chat->chat_id,
                    'actor' => $actor,
                ]);

                $this->client->answerCallback($callbackId, 'Live.');
                $this->notifier->syncShow($show->refresh());

                return;

            case 'end':
                // One press only arms the confirmation. Ending a show drops every viewer,
                // and a fat thumb in a group chat should not be able to do that.
                if ($show->status !== 'live') {
                    $this->client->answerCallback($callbackId, 'Nothing is live.', alert: true);
                    $this->notifier->syncShow($show);

                    return;
                }

                $this->rewrite($chat, $record, $show, TelegramMessage::STATE_CONFIRM_END);
                $this->client->answerCallback($callbackId, 'Confirm to end it.');

                return;

            case 'keep':
                $this->rewrite($chat, $record, $show, $this->notifier->showState($show, null));
                $this->client->answerCallback($callbackId, 'Left running.');

                return;

            case 'endnow':
                if ($show->status !== 'live') {
                    $this->client->answerCallback($callbackId, 'Nothing is live.', alert: true);
                    $this->notifier->syncShow($show);

                    return;
                }

                $show->endLivestream();

                Log::info('Telegram ended a show', [
                    'show_id' => $show->id,
                    'chat_id' => $chat->chat_id,
                    'actor' => $actor,
                ]);

                $this->client->answerCallback($callbackId, 'Ended.');
                $this->notifier->syncShow($show->refresh());

                return;

            default:
                $this->client->answerCallback($callbackId, 'Unknown button.');
        }
    }

    private function feedbackAction(string $callbackId, string $action, int $reportId, string $actor): void
    {
        $report = FeedbackReport::with(['user', 'show', 'source', 'handler'])->find($reportId);

        if (! $report) {
            $this->client->answerCallback($callbackId, 'That report is gone.', alert: true);

            return;
        }

        if ($action !== 'resolve') {
            $this->client->answerCallback($callbackId, 'Unknown button.');

            return;
        }

        $report->forceFill([
            'status' => FeedbackReport::STATUS_RESOLVED,
            // No user id: the presser is a Telegram account, so the handle is the whole
            // attribution there is.
            'handled_note' => $actor.' (Telegram)',
            'handled_at' => now(),
        ])->save();

        $this->client->answerCallback($callbackId, 'Resolved.');
        $this->notifier->syncFeedback($report->refresh());
    }

    /**
     * Rewrite the one message that was pressed, without touching the others.
     */
    private function rewrite(TelegramChat $chat, ?TelegramMessage $record, Show $show, string $state): void
    {
        if (! $record) {
            return;
        }

        $this->client->edit(
            $chat,
            $record->message_id,
            $this->notifier->showText($show, $state),
            $this->notifier->showKeyboard($chat, $show, $state),
        );

        $record->forceFill(['state' => $state])->save();
    }

    /**
     * The bot being removed from a group is the group telling us to stop writing there.
     *
     * @param  array<string, mixed>  $update
     */
    private function membership(array $update): void
    {
        $status = $update['new_chat_member']['status'] ?? null;
        $chatId = (string) ($update['chat']['id'] ?? '');
        $chat = TelegramChat::where('chat_id', $chatId)->first();

        if (! $chat) {
            return;
        }

        if (in_array($status, ['kicked', 'left'], true)) {
            $chat->disable('Removed from the chat.');

            return;
        }

        $chat->forceFill([
            'title' => $this->title($update['chat'] ?? []),
            'last_error' => null,
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $chat
     */
    private function title(array $chat): ?string
    {
        return $chat['title']
            ?? trim(($chat['first_name'] ?? '').' '.($chat['last_name'] ?? '')) ?: ($chat['username'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $from
     */
    private function actor(array $from): string
    {
        if (! empty($from['username'])) {
            return '@'.$from['username'];
        }

        $name = trim(($from['first_name'] ?? '').' '.($from['last_name'] ?? ''));

        return $name !== '' ? $name : 'someone';
    }
}
