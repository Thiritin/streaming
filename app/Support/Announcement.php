<?php

namespace App\Support;

use App\Models\BrandingSetting;
use App\Support\Manage\Settings;
use Illuminate\Support\Facades\Cache;

/**
 * The installation's announcement: a banner line on the front page and, behind it,
 * the full text as a page of its own at /announcement.
 *
 * Saved values live in the settings table next to branding, defaults in
 * config/announcement.php, resolved once under a single cache key that
 * BrandingSetting drops on every write. Off unless it is both switched on and has
 * something to say, so clearing the text takes it down without touching the toggle.
 */
final class Announcement
{
    public const CACHE_KEY = 'site_announcement';

    /**
     * Colour only.
     *
     * @var array<string, string>
     */
    public const LEVELS = [
        'info' => 'Info',
        'warning' => 'Warning',
        'critical' => 'Critical',
    ];

    private const TTL = 3600;

    /**
     * The banner as the frontend needs it, or null when there is nothing to show.
     *
     * @return array{id: string, title: ?string, html: string, text: string, level: string, link: ?array{url: string, label: string}, dismissible: bool}|null
     */
    public static function current(): ?array
    {
        return Cache::remember(self::CACHE_KEY, self::TTL, function () {
            if (! Features::announcement() || ! Settings::toBool(self::value('announcement_enabled'))) {
                return null;
            }

            $body = trim((string) self::value('announcement_body'));

            if ($body === '') {
                return null;
            }

            $title = trim((string) self::value('announcement_title'));
            $level = (string) self::value('announcement_level');
            $link = self::link();

            return [
                // A dismissal is remembered against this, so an edit shows again.
                'id' => substr(hash('sha256', $title.'|'.$body.'|'.$level.'|'.($link['url'] ?? '')), 0, 16),
                'title' => $title !== '' ? $title : null,
                'html' => Markdown::render($body),
                'text' => $body,
                'level' => array_key_exists($level, self::LEVELS) ? $level : 'info',
                'link' => $link,
                'dismissible' => Settings::toBool(self::value('announcement_dismissible')),
            ];
        });
    }

    /**
     * The "read more" link, or null when the banner is the whole message.
     *
     * @return array{url: string, label: string}|null
     */
    private static function link(): ?array
    {
        // An explicit address wins over the built-in page.
        $url = trim((string) self::value('announcement_link_url'));

        if ($url === '' && trim((string) self::value('announcement_details')) !== '') {
            $url = route('announcement', absolute: false);
        }

        if ($url === '') {
            return null;
        }

        $label = trim((string) self::value('announcement_link_label'));

        return [
            'url' => $url,
            'label' => $label !== '' ? $label : 'Read more',
        ];
    }

    /**
     * The announcement as its own page. Null when the banner is down or nothing
     * longer was written, which is what the route 404s on. Kept out of current() so
     * the full text is not on the wire for everyone.
     *
     * @return array{title: ?string, level: string, summaryHtml: string, html: string}|null
     */
    public static function page(): ?array
    {
        $banner = self::current();

        if ($banner === null) {
            return null;
        }

        $details = Markdown::render(self::value('announcement_details'));

        if ($details === null) {
            return null;
        }

        return [
            'title' => $banner['title'],
            'level' => $banner['level'],
            // The banner line as the standfirst: the sentence people clicked on.
            'summaryHtml' => $banner['html'],
            'html' => $details,
        ];
    }

    /**
     * Drop the resolved banner. Called from BrandingSetting when a row is written.
     */
    public static function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    private static function value(string $key): mixed
    {
        return BrandingSetting::getValue($key, config("announcement.{$key}"));
    }
}
