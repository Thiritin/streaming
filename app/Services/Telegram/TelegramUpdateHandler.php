<?php

namespace App\Services\Telegram;

use App\Models\ChatBan;
use App\Models\FeedbackReport;
use App\Models\Recording;
use App\Models\RecordingComment;
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

        $threadId = $this->threadId($message);

        // "/link@stream_bot CODE" in a group: Telegram appends the bot name whenever
        // more than one bot might answer.
        $parts = preg_split('/\s+/', $text) ?: [];
        $command = mb_strtolower(explode('@', ltrim((string) array_shift($parts), '/'))[0]);
        $argument = trim(implode(' ', $parts));

        $chat = $this->find($chatId, $threadId);

        match ($command) {
            'link' => $this->link($message, $argument),
            'unlink' => $this->unlink($chatId, $threadId, $chat),
            'status' => $this->status($chatId, $threadId, $chat),
            'chatid' => $this->client->reply(
                $chatId,
                'Chat id: <code>'.e($chatId).'</code>'
                .($threadId > 0 ? "\nTopic id: <code>{$threadId}</code>" : ''),
                $threadId,
            ),
            'start', 'help' => $this->client->reply($chatId, $this->help(), $threadId),
            default => null,
        };
    }

    /**
     * The topic a message came from, or 0 for a plain chat.
     *
     * General carries no thread id, and neither does a reply in a non-forum group, so
     * both land on 0 - the same row a group without topics has always had.
     *
     * @param  array<string, mixed>  $message
     */
    private function threadId(array $message): int
    {
        return ($message['is_topic_message'] ?? false)
            ? (int) ($message['message_thread_id'] ?? 0)
            : 0;
    }

    /**
     * The row for one topic. Falls back to the chat's other row when the exact topic has
     * none, so a group that was linked before topics were switched on keeps working
     * instead of going silent.
     */
    private function find(string $chatId, int $threadId): ?TelegramChat
    {
        return TelegramChat::forChat($chatId)->where('thread_id', $threadId)->first()
            ?? ($threadId > 0 ? null : TelegramChat::forChat($chatId)->first());
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
        $threadId = $this->threadId($message);
        $code = mb_strtoupper(trim($argument));

        if ($code === '') {
            $this->client->reply($chatId, 'Send <code>/link CODE</code> with the code from Settings > Telegram.', $threadId);

            return;
        }

        $link = TelegramLinkCode::where('code', $code)->first();

        if (! $link || ! $link->usable()) {
            $this->client->reply($chatId, '❌ That code is not valid any more. Generate a new one in Settings > Telegram.', $threadId);

            return;
        }

        $existing = TelegramChat::forChat($chatId)->where('thread_id', $threadId)->first();

        if ($existing) {
            $existing->forceFill([
                'title' => $this->title($message['chat']),
                'topic_title' => $this->topicTitle($message) ?? $existing->topic_title,
                'type' => $message['chat']['type'] ?? null,
                'enabled' => true,
                'last_error' => null,
            ])->save();

            $link->forceFill(['used_at' => now(), 'telegram_chat_id' => $existing->id])->save();

            $this->client->reply($chatId, "✅ Already linked.\n".e($this->notifier->summary($existing)), $threadId);

            return;
        }

        // Deliberately empty: linked, enabled, and told nothing. What a chat hears and
        // whether it gets buttons is a decision made in the panel by somebody who can
        // see which room this is, not by whoever happened to paste the code.
        $chat = TelegramChat::create([
            'chat_id' => $chatId,
            // One topic is one configuration. A forum group can send shows to the stage
            // topic and reports to the support topic, which is the whole point of using
            // topics rather than several groups.
            'thread_id' => $threadId,
            'title' => $this->title($message['chat']),
            'topic_title' => $this->topicTitle($message),
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
            ($threadId > 0
                ? "✅ <b>Linked this topic.</b>\nPosts land here rather than in General."
                : '✅ <b>Linked.</b>')
            ."\nNothing is switched on yet. Pick what this chat should get - shows, feedback, and whether it may press buttons - in /manage > Telegram.",
            $threadId,
        );

        Log::info('Telegram chat linked', ['chat_id' => $chatId, 'thread_id' => $threadId, 'code' => $code]);
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

        // The message that was pressed knows which row posted it, which is a surer answer
        // than matching the topic again - and it still works for a group whose topics were
        // rearranged after the message went out.
        $record = TelegramMessage::with('chat')
            ->where('message_id', $messageId)
            ->whereHas('chat', fn ($query) => $query->where('chat_id', $chatId))
            ->first();

        $chat = $record?->chat ?? $this->find($chatId, $this->threadId($query['message'] ?? []));

        if (! $chat || ! $chat->enabled || ! $chat->interactive) {
            $this->client->answerCallback($callbackId, 'This chat is not allowed to do that.', alert: true);

            return;
        }

        [$kind, $action, $id] = array_pad(explode(':', $data, 3), 3, null);

        match ($kind) {
            's' => $this->showAction($callbackId, $chat, $record, (string) $action, (int) $id, $actor),
            'f' => $this->feedbackAction($callbackId, (string) $action, (int) $id, $actor),
            'r' => $this->recordingAction($callbackId, (string) $action, (int) $id, $actor, $chat),
            'c' => $this->commentAction($callbackId, $record, (string) $action, (int) $id, $actor),
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
     * Moderating a reported comment from the chat: put it back, take it down, or
     * stop the account that wrote it.
     *
     * All three are decisions the panel offers, and none of them needs anything the
     * panel has that a chat does not - the report and the comment are both in the
     * message. Banning asks twice, because it is the one that does not undo itself.
     */
    private function commentAction(
        string $callbackId,
        ?TelegramMessage $record,
        string $action,
        int $commentId,
        string $actor,
    ): void {
        $comment = RecordingComment::with(['user', 'recording', 'approver'])->find($commentId);

        if (! $comment) {
            $this->client->answerCallback($callbackId, 'That comment is gone.', alert: true);

            return;
        }

        match ($action) {
            'approve' => $this->approveComment($callbackId, $comment),
            'delete' => $this->deleteComment($callbackId, $comment, $actor),
            'ban' => $this->askToBan($callbackId, $record, $comment),
            'bancancel' => $this->cancelBan($callbackId, $record, $comment),
            'banyes' => $this->banCommentAuthor($callbackId, $record, $comment, $actor),
            default => $this->client->answerCallback($callbackId, 'Unknown button.'),
        };
    }

    private function approveComment(string $callbackId, RecordingComment $comment): void
    {
        if (! $comment->isHidden()) {
            $this->client->answerCallback($callbackId, 'Already up.');
            $this->notifier->syncComment($comment);

            return;
        }

        // No user id: the presser is a Telegram account, so there is nobody to
        // attribute it to on this side.
        $comment->approve(null);

        $this->client->answerCallback($callbackId, 'Back up.');
        $this->notifier->syncComment($comment->refresh());
    }

    private function deleteComment(string $callbackId, RecordingComment $comment, string $actor): void
    {
        $author = $comment->user?->name ?? 'a deleted account';
        $id = $comment->id;

        // Replies go with it, the same as everywhere else.
        $comment->delete();

        $this->client->answerCallback($callbackId, 'Deleted.');
        $this->notifier->commentDeleted($id, $author, $actor.' (Telegram)');
    }

    private function askToBan(string $callbackId, ?TelegramMessage $record, RecordingComment $comment): void
    {
        if (! $comment->user_id) {
            $this->client->answerCallback($callbackId, 'That comment has no account behind it.', alert: true);

            return;
        }

        $record?->forceFill(['state' => TelegramMessage::STATE_CONFIRM_BAN])->save();

        $this->client->answerCallback($callbackId, 'Confirm to ban them.');
        $this->notifier->syncComment($comment);
    }

    private function cancelBan(string $callbackId, ?TelegramMessage $record, RecordingComment $comment): void
    {
        $record?->forceFill(['state' => $comment->isHidden() ? 'reported' : 'approved'])->save();

        $this->client->answerCallback($callbackId, 'Left alone.');
        $this->notifier->syncComment($comment);
    }

    private function banCommentAuthor(
        string $callbackId,
        ?TelegramMessage $record,
        RecordingComment $comment,
        string $actor,
    ): void {
        if (! $comment->user_id) {
            $this->client->answerCallback($callbackId, 'That comment has no account behind it.', alert: true);

            return;
        }

        /*
         * Permanent, and a chat ban rather than a comment-only one: the comment box
         * already refuses anyone chat has silenced. A ban meant to run out is set in
         * the panel, where the length can be chosen; from here the answer is stop.
         */
        ChatBan::create([
            'user_id' => $comment->user_id,
            'reason' => 'Comment moderation by '.$actor.' (Telegram)',
            'expires_at' => null,
        ]);

        $record?->forceFill(['state' => $comment->isHidden() ? 'reported' : 'approved'])->save();

        $this->client->answerCallback($callbackId, 'Banned.');
        $this->notifier->syncComment($comment->refresh());
    }

    /**
     * Publishing from the chat. No confirmation: publishing is reversible in a click and
     * the worst case is a recording being visible a few minutes early.
     */
    private function recordingAction(
        string $callbackId,
        string $action,
        int $recordingId,
        string $actor,
        TelegramChat $chat,
    ): void {
        $recording = Recording::with(['show', 'source'])->find($recordingId);

        if (! $recording) {
            $this->client->answerCallback($callbackId, 'That recording is gone.', alert: true);

            return;
        }

        if ($action !== 'publish') {
            $this->client->answerCallback($callbackId, 'Unknown button.');

            return;
        }

        if ($recording->is_published) {
            $this->client->answerCallback($callbackId, 'Already published.');
            $this->notifier->syncRecording($recording);

            return;
        }

        $recording->forceFill(['is_published' => true])->save();

        Log::info('Telegram published a recording', [
            'recording_id' => $recording->id,
            'chat_id' => $chat->chat_id,
            'actor' => $actor,
        ]);

        $this->client->answerCallback($callbackId, 'Published.');
        $this->notifier->syncRecording($recording->refresh());
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

        // Membership is of the group, not of one topic, so this applies to every row the
        // group has.
        $chats = TelegramChat::forChat($chatId)->get();

        foreach ($chats as $chat) {
            if (in_array($status, ['kicked', 'left'], true)) {
                $chat->disable('Removed from the chat.');

                continue;
            }

            $chat->forceFill([
                'title' => $this->title($update['chat'] ?? []),
                'last_error' => null,
            ])->save();
        }
    }

    /**
     * The topic's name, when Telegram happens to send it. It rides along on the message
     * that created the topic, so a command sent later has no name in it and the row keeps
     * whatever it already knew.
     *
     * @param  array<string, mixed>  $message
     */
    private function topicTitle(array $message): ?string
    {
        $name = $message['reply_to_message']['forum_topic_created']['name']
            ?? $message['forum_topic_created']['name']
            ?? null;

        return is_string($name) && $name !== '' ? $name : null;
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
