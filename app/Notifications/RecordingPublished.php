<?php

namespace App\Notifications;

use App\Models\Recording;
use App\Models\User;
use App\Notifications\Messages\TelegramMessage;
use App\Support\MailBranding;
use App\Support\NotificationCategories;
use App\Support\UnsubscribeLinks;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * A recording has appeared in the archive.
 *
 * Sent once per viewer per recording per transport, which is enforced by the claim on
 * `notification_deliveries` rather than by anything here - a notification is a
 * template, and the only place it is safe to decide whether somebody has already been
 * written to is the row that says so.
 *
 * Whether the viewer followed the show only changes the wording. Both routes into this
 * message are the same category, so the footer offers to switch off the same thing
 * either way.
 */
class RecordingPublished extends Notification
{
    use Queueable;

    /**
     * @param  array<int, string>  $channels
     */
    public function __construct(
        public readonly Recording $recording,
        private readonly array $channels,
        private readonly bool $followed = false,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return $this->channels;
    }

    public function toMail(User $notifiable): MailMessage
    {
        $recording = $this->payload();

        return (new MailMessage)
            ->subject($this->subject())
            ->view('emails.recording-published', [
                'brand' => MailBranding::all(),
                'heading' => $this->heading(),
                'eyebrow' => $this->eyebrow(),
                'intro' => $this->intro($notifiable),
                'recording' => $recording,
                'unsubscribe' => UnsubscribeLinks::forUser($notifiable, NotificationCategories::RECORDINGS),
            ]);
    }

    public function toTelegram(User $notifiable): TelegramMessage
    {
        $recording = $this->payload();

        $lines = ['<b>'.e($recording['title']).'</b>'];

        if ($recording['meta']) {
            $lines[] = e($recording['meta']);
        }

        if ($recording['description']) {
            $lines[] = '';
            $lines[] = e($recording['description']);
        }

        return TelegramMessage::make()
            ->text(implode("\n", $lines))
            ->photo($recording['thumbnail'])
            ->links(['Watch it' => $recording['url']]);
    }

    private function subject(): string
    {
        return $this->followed
            ? 'Now in the archive: '.$this->recording->title
            : 'New recording: '.$this->recording->title;
    }

    private function heading(): string
    {
        return $this->recording->title;
    }

    private function eyebrow(): string
    {
        return $this->followed ? 'A show you follow' : 'New in the archive';
    }

    private function intro(User $notifiable): string
    {
        $name = Str::before(trim($notifiable->name), ' ') ?: 'there';

        return $this->followed
            ? "Hi {$name}, the recording of a show you followed is up."
            : "Hi {$name}, something new has just been published to the archive.";
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        $recording = $this->recording;

        return [
            'title' => $recording->title,
            'url' => route('recordings.show', $recording->id),
            // The permanent redirect, not the presigned URL on the model: a message read
            // a week later would otherwise show a broken image where the still was.
            'thumbnail' => $recording->thumbnail_path
                ? route('recordings.thumbnail', $recording->id)
                : null,
            'category' => $recording->effectiveCategory()?->name,
            'meta' => $this->meta(),
            'description' => $recording->description
                ? Str::limit(strip_tags($recording->description), 220)
                : null,
        ];
    }

    /**
     * Deliberately no source. Which room a recording came out of is an operations
     * detail, and the archive does not show it either.
     */
    private function meta(): ?string
    {
        $parts = array_filter([
            $this->recording->date?->format('j M Y'),
            $this->recording->formatted_duration ?: null,
        ]);

        return $parts === [] ? null : implode(' · ', $parts);
    }
}
