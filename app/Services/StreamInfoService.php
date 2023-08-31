<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class StreamInfoService
{
    public static function getUserCount(): int
    {
        return Cache::remember('stream.listeners', 30,
            function () {
                return DB::table('clients')
                    ->whereNotNull('start')
                    ->whereNull('stop')
                    ->distinct('server_user_id')
                    ->count('server_user_id') ?? 0;
            });
    }

    public static function getPreviousUserCount(): int
    {
        return Cache::get('stream.listeners.old', fn() => 0);
    }

    public static function setPreviousUserCount(int $count): bool
    {
        return Cache::set('stream.listeners.old', $count);
    }
}
