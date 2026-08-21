<?php

namespace App\Services\Telegram;

use App\Models\FeedbackReport;
use App\Models\Show;
use App\Models\TelegramChat;
use App\Models\TelegramMessage;
use Illuminate\Support\Collection;

/**
 * What the bot actually says, and to whom.
 *
 * Two subjects, and both of them are living messages rather than notifications. A show
 * is posted once, a few minutes before its slot, and that same message then carries the
 * Start button, becomes the live message with an End button, and finally settles as a
 * line of history. A report is posted when it arrives and rewritten when it is resolved,
 * whether that happened here or in /manage.
 *
 * Which chats hear about what is per chat: the notify flags and the source list on
 * TelegramChat. Whether a chat gets buttons at all is `interactive` - an info-only chat
 * gets the same text with a link into the panel, because anybody who can read the group
 * could otherwise press them.
 */
class TelegramNotifier
{
    public function __construct(private readonly TelegramClient $client) {}

    /**
     * Announce a report that just came in.
     */
    public function feedbackCreated(FeedbackReport $report): void
    {
        if (! $this->client->enabled()) {
            return;
        }

        foreach ($this->chatsFor('notify_feedback', $report->source_id) as $chat) {
            $messageId = $this->client->send(
                $chat,
                $this->feedbackText($report),
                $this->feedbackKeyboard($chat, $report),
            );

            if ($messageId !== null) {
                TelegramMessage::create([
                    'telegram_chat_id' => $chat->id,
                    'message_id' => $messageId,
                    'kind' => TelegramMessage::KIND_FEEDBACK,
                    'subject_id' => $report->id,
                    'state' => $report->status,
                ]);
            }
        }
    }

    /**
     * Announce a show that is about to start. Chats that already have a message for it
     * are skipped, which is what makes the every-minute scan safe to run.
     */
    public function upcomingShow(Show $show): void
    {
        if (! $this->client->enabled()) {
            return;
        }

        $this->postToChatsWithout($show);
    }

    /**
     * Post to the chats that should have a message about this show and do not.
     *
     * Used both by the every-minute scan and by a show that went live without ever
     * being announced - started long before its slot, started by auto mode, or created
     * live in the first place. The state comes from the show, so a message posted at
     * that point arrives reading as live, with the End button rather than Start.
     */
    private function postToChatsWithout(Show $show): void
    {
        $already = TelegramMessage::where('kind', TelegramMessage::KIND_SHOW)
            ->where('subject_id', $show->id)
            ->pluck('telegram_chat_id')
            ->all();

        foreach ($this->chatsFor('notify_shows', $show->source_id) as $chat) {
            if (in_array($chat->id, $already, true)) {
                continue;
            }

            $state = $this->showState($show, null);

            $messageId = $this->client->send(
                $chat,
                $this->showText($show, $state),
                $this->showKeyboard($chat, $show, $state),
            );

            if ($messageId !== null) {
                TelegramMessage::create([
                    'telegram_chat_id' => $chat->id,
                    'message_id' => $messageId,
                    'kind' => TelegramMessage::KIND_SHOW,
                    'subject_id' => $show->id,
                    'state' => $state,
                ]);
            }
        }
    }

    /**
     * Bring every posted message for this show back in line with the show.
     *
     * Called whenever a show changes status, however it changed: pressed in Telegram,
     * pressed in /manage, pressed on a control surface, or ended by auto mode. The
     * button in the chat is only trustworthy because of this.
     */
    public function syncShow(Show $show): void
    {
        if (! $this->client->enabled()) {
            return;
        }

        // A show that went live before anyone announced it has nothing to rewrite, so
        // the chat would otherwise never hear that it is on air. Post it now, live.
        if ($show->status === 'live') {
            $this->postToChatsWithout($show);
        }

        foreach ($this->messagesFor(TelegramMessage::KIND_SHOW, $show->id) as $message) {
            $state = $this->showState($show, $message->state);

            $this->client->edit(
                $message->chat,
                $message->message_id,
                $this->showText($show, $state),
                $this->showKeyboard($message->chat, $show, $state),
            );

            $message->forceFill(['state' => $state])->save();
        }
    }

    public function syncFeedback(FeedbackReport $report): void
    {
        if (! $this->client->enabled()) {
            return;
        }

        foreach ($this->messagesFor(TelegramMessage::KIND_FEEDBACK, $report->id) as $message) {
            $this->client->edit(
                $message->chat,
                $message->message_id,
                $this->feedbackText($report),
                $this->feedbackKeyboard($message->chat, $report),
            );

            $message->forceFill(['state' => $report->status])->save();
        }
    }

    /**
     * Put one message into a chat, so an operator can prove the bot can write there
     * before a show depends on it.
     */
    public function test(TelegramChat $chat): bool
    {
        return $this->client->send(
            $chat,
            '✅ <b>'.$this->escape(config('app.name')).'</b> can post here.'
            ."\n".$this->escape($this->summary($chat)),
        ) !== null;
    }

    /**
     * The state a message about this show should be in. A pending end confirmation
     * survives, because the show is still live while somebody is being asked whether
     * they meant it.
     */
    public function showState(Show $show, ?string $current): string
    {
        if ($show->status === 'live') {
            return $current === TelegramMessage::STATE_CONFIRM_END
                ? TelegramMessage::STATE_CONFIRM_END
                : TelegramMessage::STATE_LIVE;
        }

        return $show->status === 'scheduled'
            ? TelegramMessage::STATE_UPCOMING
            : TelegramMessage::STATE_CLOSED;
    }

    public function showText(Show $show, string $state): string
    {
        $lines = [];
        $title = $this->escape($show->title);
        $source = $this->escape($show->source?->name ?? 'No source');
        $slot = $this->slot($show);

        $lines[] = match ($state) {
            TelegramMessage::STATE_LIVE, TelegramMessage::STATE_CONFIRM_END => "🔴 <b>{$title}</b>",
            TelegramMessage::STATE_CLOSED => "⚫️ <b>{$title}</b>",
            default => "🎬 <b>{$title}</b>",
        };

        $lines[] = $source.($slot ? ' · '.$slot : '');

        $lines[] = match (true) {
            $state === TelegramMessage::STATE_CONFIRM_END => 'End this show? Viewers stop watching immediately.',
            $state === TelegramMessage::STATE_LIVE => 'Live since '.$this->time($show->actual_start).'.',
            $show->status === 'cancelled' => 'Cancelled.'.($show->cancellation_reason
                ? ' '.$this->escape($show->cancellation_reason)
                : ''),
            $show->status === 'ended' => 'Ended at '.$this->time($show->actual_end).'.',
            default => 'Starts '.$this->relative($show->scheduled_start).'.',
        };

        return implode("\n", $lines);
    }

    /**
     * @return array<int, array<int, array<string, string>>>
     */
    public function showKeyboard(TelegramChat $chat, Show $show, string $state): array
    {
        $open = [['text' => 'Open in panel', 'url' => route('manage.shows.edit', $show)]];

        if (! $chat->interactive || $state === TelegramMessage::STATE_CLOSED) {
            return [$open];
        }

        return match ($state) {
            TelegramMessage::STATE_UPCOMING => [
                [['text' => '▶️ Start show', 'callback_data' => "s:start:{$show->id}"]],
                $open,
            ],
            TelegramMessage::STATE_LIVE => [
                [['text' => '⏹ End show', 'callback_data' => "s:end:{$show->id}"]],
                $open,
            ],
            // The confirmation is the same message with two different buttons, so
            // nothing new arrives in the chat for a press somebody is about to take back.
            TelegramMessage::STATE_CONFIRM_END => [
                [
                    ['text' => '✅ Yes, end it', 'callback_data' => "s:endnow:{$show->id}"],
                    ['text' => 'Cancel', 'callback_data' => "s:keep:{$show->id}"],
                ],
            ],
            default => [$open],
        };
    }

    public function feedbackText(FeedbackReport $report): string
    {
        $lines = [];

        $lines[] = $report->type === FeedbackReport::TYPE_ISSUE
            ? '🛠 <b>Stream issue</b>'
            : '💬 <b>Feedback</b>';

        $from = $this->escape($report->reporterName());

        if ($report->telegram && $report->user) {
            $from .= ' ('.$this->escape($report->telegramHandle()).')';
        }

        $lines[] = 'From: '.$from;

        if ($report->show) {
            $lines[] = 'Watching: '.$this->escape($report->show->title)
                .($report->source ? ' · '.$this->escape($report->source->name) : '');
        }

        $browser = $report->diagnostics['browser']['name'] ?? null;

        if (is_string($browser) && $browser !== '') {
            $lines[] = 'Browser: '.$this->escape($browser);
        }

        $lines[] = '';
        $lines[] = '<i>'.$this->escape($report->excerpt(600)).'</i>';

        if ($report->status === FeedbackReport::STATUS_RESOLVED) {
            $by = $report->handled_note ?: $report->handler?->name;
            $lines[] = '';
            $lines[] = '✅ Resolved'.($by ? ' by '.$this->escape($by) : '').'.';
        }

        return implode("\n", $lines);
    }

    /**
     * @return array<int, array<int, array<string, string>>>
     */
    public function feedbackKeyboard(TelegramChat $chat, FeedbackReport $report): array
    {
        $rows = [];

        if ($chat->interactive && $report->status !== FeedbackReport::STATUS_RESOLVED) {
            $rows[] = [['text' => '✅ Resolve', 'callback_data' => "f:resolve:{$report->id}"]];
        }

        $rows[] = [['text' => 'Open in panel', 'url' => route('manage.feedback.show', $report)]];

        return $rows;
    }

    /**
     * One line describing what a chat is set to receive, for /status and the test post.
     */
    public function summary(TelegramChat $chat): string
    {
        $parts = [];

        if ($chat->notify_shows) {
            $parts[] = 'shows';
        }

        if ($chat->notify_feedback) {
            $parts[] = 'feedback';
        }

        $what = $parts === [] ? 'nothing yet' : implode(' and ', $parts);
        $mode = $chat->interactive ? 'with buttons' : 'info only';
        $sources = ($chat->source_ids ?? []) === []
            ? 'all sources'
            : $chat->sources()->pluck('name')->implode(', ');

        return "This chat gets {$what} ({$mode}) for {$sources}.";
    }

    /**
     * The enabled chats that asked for this kind of message and cover this source.
     *
     * @return Collection<int, TelegramChat>
     */
    private function chatsFor(string $flag, ?int $sourceId): Collection
    {
        return TelegramChat::active()
            ->where($flag, true)
            ->get()
            ->filter(fn (TelegramChat $chat) => $chat->coversSource($sourceId))
            ->values();
    }

    /**
     * @return Collection<int, TelegramMessage>
     */
    private function messagesFor(string $kind, int $subjectId): Collection
    {
        return TelegramMessage::with('chat')
            ->where('kind', $kind)
            ->where('subject_id', $subjectId)
            ->get()
            ->filter(fn (TelegramMessage $message) => $message->chat && $message->chat->enabled)
            ->values();
    }

    private function slot(Show $show): ?string
    {
        if (! $show->scheduled_start) {
            return null;
        }

        $start = $this->time($show->scheduled_start);

        return $show->scheduled_end ? $start.'–'.$this->time($show->scheduled_end) : $start;
    }

    private function time(mixed $value): string
    {
        return $value ? $value->timezone(config('app.timezone'))->format('H:i') : 'unknown';
    }

    private function relative(mixed $value): string
    {
        return $value ? $value->diffForHumans() : 'at an unknown time';
    }

    private function escape(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
