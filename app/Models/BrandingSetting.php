<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class BrandingSetting extends Model
{
    protected $fillable = [
        'key',
        'value',
        'description',
    ];

    /**
     * Get a branding value by key, falling back to the config default.
     */
    public static function getValue(string $key, $default = null)
    {
        $stored = Cache::remember("branding_setting_{$key}", 3600, function () use ($key) {
            // Sentinel, so a legitimately empty saved value is not mistaken for
            // a cache miss on every request.
            return self::where('key', $key)->value('value') ?? '__unset__';
        });

        if ($stored === '__unset__') {
            return $default ?? config("branding.{$key}");
        }

        return $stored;
    }

    /**
     * Set a branding value.
     */
    public static function setValue(string $key, $value, ?string $description = null): self
    {
        $setting = self::updateOrCreate(
            ['key' => $key],
            [
                'value' => $value,
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
        });

        static::deleted(function ($setting) {
            Cache::forget("branding_setting_{$setting->key}");
        });
    }
}
