<?php

namespace App\Models;

use App\Support\Announcement;
use App\Support\Features;
use App\Support\RuntimeConfig;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class BrandingSetting extends Model
{
    protected $fillable = [
        'key',
        'value',
        'encrypted',
        'description',
    ];

    protected $casts = [
        'encrypted' => 'boolean',
    ];

    /**
     * Get a branding value by key, falling back to the config default.
     *
     * The cache holds the row as stored, so a secure value sits there as ciphertext
     * and is decrypted per read rather than once into the cache store.
     */
    public static function getValue(string $key, $default = null)
    {
        $stored = Cache::remember("branding_setting_{$key}", 3600, function () use ($key) {
            // Asked rather than caught: code can be deployed ahead of its migration,
            // and this read is on the boot path of every request.
            $encrypted = Schema::hasColumn('branding_settings', 'encrypted');

            $row = self::where('key', $key)->first($encrypted ? ['value', 'encrypted'] : ['value']);

            // Sentinel, so a legitimately empty saved value is not mistaken for
            // a cache miss on every request.
            if ($row === null || $row->value === null) {
                return '__unset__';
            }

            return ['value' => $row->value, 'encrypted' => $encrypted && (bool) $row->encrypted];
        });

        if ($stored === '__unset__') {
            return $default ?? config("branding.{$key}");
        }

        // A row cached before the encrypted flag existed is a bare string.
        if (! is_array($stored)) {
            return $stored;
        }

        $plain = self::plain($stored['value'], $stored['encrypted'], $key);

        return $plain ?? ($default ?? config("branding.{$key}"));
    }

    /**
     * A stored value as plaintext, or null when an encrypted one cannot be read -
     * a rotated APP_KEY should hand the key back to its default, not throw.
     */
    public static function plain(?string $value, bool $encrypted, string $key = ''): ?string
    {
        if ($value === null || ! $encrypted) {
            return $value;
        }

        try {
            return Crypt::decryptString($value);
        } catch (\Throwable) {
            Log::warning('Encrypted setting could not be decrypted', ['key' => $key]);

            return null;
        }
    }

    /**
     * Set a branding value, encrypted at rest when the field asks for it.
     */
    public static function setValue(string $key, $value, ?string $description = null, bool $encrypt = false): self
    {
        $encrypt = $encrypt && is_string($value) && $value !== '';

        $setting = self::updateOrCreate(
            ['key' => $key],
            [
                'value' => $encrypt ? Crypt::encryptString($value) : $value,
                'encrypted' => $encrypt,
                'description' => $description,
            ]
        );

        Cache::forget("branding_setting_{$key}");

        return $setting;
    }

    /**
     * Clear the cache when saving.
     */
    protected static function booted()
    {
        static::saved(function ($setting) {
            Cache::forget("branding_setting_{$setting->key}");
            Features::flush();
            Announcement::flush();
            RuntimeConfig::flush();
        });

        static::deleted(function ($setting) {
            Cache::forget("branding_setting_{$setting->key}");
            Features::flush();
            Announcement::flush();
            RuntimeConfig::flush();
        });
    }
}
