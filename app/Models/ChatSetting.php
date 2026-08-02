<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class ChatSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'key',
        'source_id',
        'value',
        'description',
    ];

    /**
     * Get a setting value, falling back from the source-scoped row to the global row.
     */
    public static function getValue(string $key, $default = null, ?int $sourceId = null)
    {
        return Cache::remember(self::cacheKey($key, $sourceId), 3600, function () use ($key, $default, $sourceId) {
            if ($sourceId !== null) {
                $scoped = self::where('key', $key)->where('source_id', $sourceId)->first();

                if ($scoped) {
                    return $scoped->value;
                }
            }

            $global = self::where('key', $key)->whereNull('source_id')->first();

            return $global ? $global->value : $default;
        });
    }

    public static function setValue(string $key, $value, ?string $description = null, ?int $sourceId = null): self
    {
        $setting = self::updateOrCreate(
            ['key' => $key, 'source_id' => $sourceId],
            ['value' => (string) $value, 'description' => $description],
        );

        self::forget($key, $sourceId);

        return $setting;
    }

    public static function forget(string $key, ?int $sourceId = null): void
    {
        Cache::forget(self::cacheKey($key, $sourceId));

        if ($sourceId !== null) {
            return;
        }

        // A changed global default invalidates every source-scoped read of the same key.
        Cache::forget(self::cacheKey($key, null));
    }

    protected static function cacheKey(string $key, ?int $sourceId): string
    {
        return 'chat_setting_'.$key.'_'.($sourceId ?? 'global');
    }

    protected static function booted(): void
    {
        static::saved(fn (self $setting) => self::forget($setting->key, $setting->source_id));
        static::deleted(fn (self $setting) => self::forget($setting->key, $setting->source_id));
    }
}
