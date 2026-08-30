<?php

use App\Support\RuntimeConfig;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * RTMP forwarding and the venue network override are gone, so the rows an
 * installation saved for them are values nothing reads any more.
 */
return new class extends Migration
{
    private const KEYS = [
        'rtmp_forward_url',
        'rtmp_forward_vrchat_url',
        'local_streaming_ipv4_subnet',
        'local_streaming_ipv6_subnet',
        'local_streaming_hostname',
    ];

    public function up(): void
    {
        DB::table('branding_settings')->whereIn('key', self::KEYS)->delete();

        foreach (self::KEYS as $key) {
            Cache::forget("branding_setting_{$key}");
        }

        Cache::forget(RuntimeConfig::CACHE_KEY);
    }

    public function down(): void
    {
        // The settings they belonged to no longer exist.
    }
};
