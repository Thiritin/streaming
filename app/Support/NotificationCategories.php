<?php

namespace App\Support;

/**
 * What a viewer can be told about.
 *
 * Two categories, each drawn as wide as its scope says: a show going on air, and a
 * recording appearing in the archive. A category key is written into signed
 * unsubscribe URLs that outlive a deploy, so renaming one orphans every link already
 * sitting in somebody's inbox.
 */
final class NotificationCategories
{
    public const SHOWS_LIVE = 'shows_live';

    public const RECORDINGS = 'recordings';

    /**
     * The sentence each category is written as on the settings page, split around the
     * inline scope control. "Tell me when [any|a followed] show goes on air."
     *
     * @return array<string, array{label: string, before: string, after: string}>
     */
    public static function all(): array
    {
        return [
            self::SHOWS_LIVE => [
                'label' => 'Shows going live',
                'before' => 'Tell me when',
                'after' => 'show goes on air',
            ],
            self::RECORDINGS => [
                'label' => 'New recordings',
                'before' => 'Tell me when a recording of',
                'after' => 'show is published',
            ],
        ];
    }

    public static function label(string $key): string
    {
        return self::all()[$key]['label'] ?? $key;
    }

    /**
     * The user column each category's scope is stored in.
     */
    public static function column(string $key): ?string
    {
        return match ($key) {
            self::SHOWS_LIVE => 'notify_shows_live',
            self::RECORDINGS => 'notify_recordings',
            default => null,
        };
    }

    /**
     * @return array<int, string>
     */
    public static function keys(): array
    {
        return array_keys(self::all());
    }
}
