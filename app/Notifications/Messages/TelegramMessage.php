<?php

namespace App\Notifications\Messages;

/**
 * What a notification hands the Telegram channel: HTML text, optionally a picture to
 * hang it on, and optionally buttons.
 */
class TelegramMessage
{
    public string $text = '';

    public ?string $photoUrl = null;

    /** @var array<int, array<int, array<string, string>>>|null */
    public ?array $keyboard = null;

    public static function make(): self
    {
        return new self;
    }

    public function text(string $text): self
    {
        $this->text = $text;

        return $this;
    }

    public function photo(?string $url): self
    {
        $this->photoUrl = $url;

        return $this;
    }

    /**
     * One row of link buttons. A viewer's chat is never interactive - it has no row in
     * `telegram_chats` and so no `interactive` flag - so these are always URLs, never
     * callbacks the bot would have to authorise.
     *
     * @param  array<string, string>  $links  label => url
     */
    public function links(array $links): self
    {
        $this->keyboard = [array_map(
            fn (string $label, string $url) => ['text' => $label, 'url' => $url],
            array_keys($links),
            array_values($links),
        )];

        return $this;
    }
}
