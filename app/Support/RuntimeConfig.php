<?php

namespace App\Support;

use App\Models\BrandingSetting;
use App\Support\Manage\Settings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * The settings table laid over the config repository.
 *
 * Every field in config/settings.php names a config path - `config` when it says so,
 * `{store}.{key}` when it does not - and a saved row for that field wins over whatever
 * the shipped config file said. So a call site keeps reading `config('services.oidc.url')`
 * and never learns that an administrator changed it at runtime.
 *
 * The whole map is resolved in one query and held under a single cache key that
 * BrandingSetting drops on every write, next to the ones Features and Announcement use.
 * Secure values stay in the cache as ciphertext and are decrypted per read.
 *
 * Nothing here may throw: it runs in a service provider's boot, which is also where a
 * fresh install, a migrate:fresh and an image build are, none of which have the table.
 */
final class RuntimeConfig
{
    public const CACHE_KEY = 'runtime_config_overrides';

    private const TTL = 3600;

    /**
     * What the last apply in this process wrote, so a long-lived worker only pays for
     * purging resolved services when the answers actually changed.
     */
    private static ?string $applied = null;

    /**
     * What stood at each overridden path before this process touched it, so revert()
     * can put the shipped config back exactly as it was.
     *
     * @var array<string, mixed>
     */
    private static array $shipped = [];

    /**
     * Set by revert(), and the reason it is a latch rather than a one-off restore.
     * config:cache boots a second application out of bootstrap/app.php to dump, and
     * that application becomes the container instance and boots this provider again -
     * so putting the values back once would only be undone a moment later. Statics are
     * per-process, which is exactly the scope that second boot shares.
     */
    private static bool $suspended = false;

    /**
     * Apply the saved overrides onto the config repository.
     */
    public static function apply(): void
    {
        if (self::$suspended) {
            return;
        }

        $overrides = self::overrides();
        $touched = array_keys($overrides);

        // A row deleted since the last apply is a path that has to go back to what it
        // displaced. Without this the process would serve a value nobody has saved for
        // the rest of its life, and the panel would go on calling it an override.
        foreach (self::$shipped as $path => $value) {
            if (array_key_exists($path, $overrides)) {
                continue;
            }

            config()->set($path, $value);
            unset(self::$shipped[$path]);
            $touched[] = $path;
        }

        foreach ($overrides as $path => $value) {
            if (! array_key_exists($path, self::$shipped)) {
                self::$shipped[$path] = config($path);
            }

            config()->set($path, $value);
        }

        $fingerprint = md5(serialize($overrides));

        if ($fingerprint === self::$applied) {
            return;
        }

        self::$applied = $fingerprint;

        self::purgeDisks($touched);
    }

    /**
     * What config would answer for a path with nothing saved against it.
     *
     * The panel needs this and not config(): once the overlay is on, reading the path
     * hands back the saved value, so a field would be compared against itself and
     * "back to the default" could never delete its row.
     */
    public static function shipped(string $path): mixed
    {
        return array_key_exists($path, self::$shipped) ? self::$shipped[$path] : config($path);
    }

    /**
     * Put the shipped config back and stop overriding for the rest of the process.
     *
     * For config:cache and optimize, which write the repository to
     * bootstrap/cache/config.php: a saved value baked into that file would pin today's
     * answers into the build, and a secure one would land on disk as plaintext. The
     * command name comes off CommandStarting rather than off argv, so a wrapper or a
     * rewritten argv cannot walk past it.
     */
    public static function revert(): void
    {
        self::$suspended = true;

        foreach (self::$shipped as $path => $value) {
            config()->set($path, $value);
        }

        $paths = array_keys(self::$shipped);

        self::$shipped = [];
        self::$applied = null;

        self::purgeDisks($paths);
    }

    /**
     * Override again after a revert. Nothing in production needs it - config:cache and
     * optimize both end the process - but a test that runs one has to hand the rest of
     * the suite an application that still reads its settings.
     */
    public static function resume(): void
    {
        self::$suspended = false;
        self::$applied = null;
    }

    /**
     * Drop any disk this overrode from the filesystem manager's cache.
     *
     * FilesystemManager memoises a resolved disk and Octane's own reset hands it a new
     * application without clearing that, so a disk touched once before a setting was
     * saved would go on using the credentials it was built with for the life of the
     * worker. Purging here rather than in the save path is the point: a save reaches one
     * process, and every other worker learns about it on its next apply.
     *
     * @param  array<int, string>  $paths
     */
    private static function purgeDisks(array $paths): void
    {
        $disks = [];

        foreach ($paths as $path) {
            if (preg_match('/^filesystems\.disks\.([^.]+)(?:\.|$)/', $path, $matches) === 1) {
                $disks[$matches[1]] = true;
            }
        }

        try {
            foreach (array_keys($disks) as $disk) {
                Storage::forgetDisk($disk);
            }
        } catch (\Throwable) {
            // A disk that cannot be forgotten is one nothing has resolved yet.
        }
    }

    /**
     * The overrides as config wants them: path => cast value.
     *
     * @return array<string, mixed>
     */
    public static function overrides(): array
    {
        $overrides = [];

        foreach (self::map() as $path => $row) {
            $value = BrandingSetting::plain($row['value'], $row['encrypted'], $row['key']);

            if ($value === null || $value === '') {
                continue;
            }

            $overrides[$path] = self::cast($value, $row['cast']);
        }

        return $overrides;
    }

    public static function flush(): void
    {
        // So the next apply in this process purges rather than trusting its fingerprint.
        self::$applied = null;

        try {
            Cache::forget(self::CACHE_KEY);
        } catch (\Throwable) {
            // Nothing to drop when the cache store is unreachable.
        }
    }

    /**
     * Every registry field that has a saved row, as stored.
     *
     * @return array<string, array{key: string, value: string, encrypted: bool, cast: string}>
     */
    private static function map(): array
    {
        try {
            return Cache::remember(self::CACHE_KEY, self::TTL, fn () => self::resolve());
        } catch (\Throwable) {
            // No table, no database, no cache: the shipped config stands on its own.
            return [];
        }
    }

    /**
     * @return array<string, array{key: string, value: string, encrypted: bool, cast: string}>
     */
    private static function resolve(): array
    {
        // Through declaredFields, so a pane that groups its fields into cards is read
        // the same as a flat one. A card-declared field with a config path that this
        // missed would look saved on the page and do nothing at its call site.
        $fields = collect(config('settings.groups', []))
            ->flatMap(fn (array $group) => Settings::declaredFields($group))
            ->keyBy('key');

        if ($fields->isEmpty()) {
            return [];
        }

        // Asked rather than caught, so code deployed ahead of its migration is an
        // ordinary answer here instead of a swallowed query exception every boot.
        $encrypted = Schema::hasColumn('branding_settings', 'encrypted');

        $rows = BrandingSetting::query()
            ->whereIn('key', $fields->keys()->all())
            ->get($encrypted ? ['key', 'value', 'encrypted'] : ['key', 'value']);

        $map = [];

        foreach ($rows as $row) {
            if ($row->value === null || $row->value === '') {
                continue;
            }

            $field = $fields[$row->key];

            $map[Settings::configPath($field)] = [
                'key' => $row->key,
                'value' => $row->value,
                'encrypted' => $encrypted && (bool) $row->encrypted,
                'cast' => Settings::castOf($field),
            ];
        }

        return $map;
    }

    private static function cast(string $value, string $cast): mixed
    {
        return match ($cast) {
            'int' => (int) $value,
            'float' => (float) $value,
            'bool' => Settings::toBool($value),
            'array' => is_array($decoded = json_decode($value, true)) ? $decoded : [],
            default => $value,
        };
    }
}
