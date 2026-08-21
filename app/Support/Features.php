<?php

namespace App\Support;

use App\Models\BrandingSetting;
use App\Models\User;
use App\Support\Manage\Settings;
use Illuminate\Support\Facades\Cache;

/**
 * The installation's feature switches: what parts of the site exist at all.
 *
 * Two layers. The installation's own switches decide what exists; a signed-in
 * viewer can then turn any of those off for themselves in /settings, which is
 * what forUser() folds in. A viewer can only ever subtract, so a feature the
 * installation has switched off stays off for everybody.
 *
 * Saved values live in the settings table next to branding, defaults in
 * config/features.php. The whole set is resolved in one go and held in the
 * cache under a single key, so asking about a flag costs a cache read rather
 * than a query. BrandingSetting drops the key whenever a setting is written,
 * which is deliberately not a per-request memo: a queue worker lives for hours
 * and has to see a flag flipped by the panel without being restarted.
 */
final class Features
{
    public const CACHE_KEY = 'feature_flags';

    /**
     * Flags the installation owns outright. A viewer opting out of an announcement
     * or of the report button is not a preference worth offering.
     *
     * @var array<int, string>
     */
    private const INSTALLATION_ONLY = ['announcement', 'feedback', 'screens', 'telegram'];

    private const TTL = 3600;

    /**
     * Every flag, resolved.
     *
     * @return array<string, bool>
     */
    public static function all(): array
    {
        return Cache::remember(self::CACHE_KEY, self::TTL, function () {
            $keys = array_keys(config('features', []));

            $saved = BrandingSetting::whereIn('key', $keys)->pluck('value', 'key');

            $flags = [];

            foreach ($keys as $key) {
                $flags[$key] = Settings::toBool(
                    $saved->has($key) ? $saved->get($key) : config("features.{$key}")
                );
            }

            return $flags;
        });
    }

    public static function enabled(string $key): bool
    {
        return self::all()[$key] ?? false;
    }

    /**
     * The flags as one viewer sees them: the installation's switches with that
     * viewer's own opt-outs applied. A guest has nowhere to save a preference,
     * so a guest sees the installation's set unchanged.
     *
     * @return array<string, bool>
     */
    public static function forUser(?User $user): array
    {
        $flags = self::all();
        $flags['emotes'] = $flags['emotes'] && $flags['chat'];

        $preferences = $user?->feature_preferences ?? [];

        foreach ($flags as $key => $enabled) {
            if (in_array($key, self::INSTALLATION_ONLY, true)) {
                continue;
            }

            // Only an explicit false counts. An absent key means the viewer has
            // never touched this feature, which is not the same as turning it off.
            if ($enabled && array_key_exists($key, $preferences) && $preferences[$key] === false) {
                $flags[$key] = false;
            }
        }

        // Same implication on the viewer's side: turning chat off takes the
        // emotes that only exist inside it with it.
        $flags['emotes'] = $flags['emotes'] && $flags['chat'];

        return $flags;
    }

    public static function enabledFor(string $key, ?User $user): bool
    {
        return self::forUser($user)[$key] ?? false;
    }

    /**
     * The keys a viewer is allowed to switch: everything the installation has
     * on. Emotes drop out of the list when chat is off, because chat already
     * decides them.
     *
     * @return array<int, string>
     */
    public static function switchableKeys(): array
    {
        $flags = self::all();
        $flags['emotes'] = $flags['emotes'] && $flags['chat'];

        return array_values(array_diff(array_keys(array_filter($flags)), self::INSTALLATION_ONLY));
    }

    public static function chat(): bool
    {
        return self::enabled('chat');
    }

    /**
     * Emotes only exist inside chat, so chat being off takes them with it.
     */
    public static function emotes(): bool
    {
        return self::chat() && self::enabled('emotes');
    }

    public static function boops(): bool
    {
        return self::enabled('boops');
    }

    public static function announcement(): bool
    {
        return self::enabled('announcement');
    }

    public static function feedback(): bool
    {
        return self::enabled('feedback');
    }

    public static function screens(): bool
    {
        return self::enabled('screens');
    }

    public static function telegram(): bool
    {
        return self::enabled('telegram');
    }

    /**
     * Drop the resolved set. Called from BrandingSetting when a row is written.
     */
    public static function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
