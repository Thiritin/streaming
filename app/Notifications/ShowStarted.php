<?php

namespace App\Notifications;

use App\Models\Show;
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
 * A show somebody followed has gone live.
 *
 * The one notification with no delay on it. A recording can wait four hours because it
 * will still be there; a show that started is worth nothing an hour later, so this is
 * sent from the status change itself.
 */
class ShowStarted extends Notification
{
    use Queueable;

    /**
     * @param  array<int, string>  $channels
     */
    public function __construct(
        public readonly Show $show,
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
        return (new MailMessage)
            ->subject('Live now: '.$this->show->title)
            ->view('emails.show-live', [
                'brand' => MailBranding::all(),
                'heading' => $this->show->title,
                'intro' => $this->intro($notifiable),
                'show' => $this->payload(),
                'unsubscribe' => UnsubscribeLinks::forUser($notifiable, NotificationCategories::SHOWS_LIVE),
            ]);
    }

    public function toTelegram(User $notifiable): TelegramMessage
    {
        $show = $this->payload();

        $lines = ['🔴 <b>'.e($show['title']).'</b> is live now'];

        if ($show['meta']) {
            $lines[] = e($show['meta']);
        }

        return TelegramMessage::make()
            ->text(implode("\n", $lines))
            ->links(['Watch now' => $show['url']]);
    }

    private function intro(User $notifiable): string
    {
        $name = Str::before(trim($notifiable->name), ' ') ?: 'there';

        return $this->followed
            ? "Hi {$name}, a show you followed has just started."
            : "Hi {$name}, a show has just started.";
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            'title' => $this->show->title,
            'url' => route('show.view', $this->show->slug),
            // No source: which room it is coming out of is not the viewer's business,
            // and the guide does not name it either.
            'meta' => null,
            'description' => $this->show->description
                ? Str::limit(strip_tags($this->show->description), 220)
                : null,
        ];
    }
}
