<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class StreamInfoService
{
    /**
     * People currently watching anything, signed in or not.
     *
     * Counted in two halves rather than one query. `COUNT(user_id)` skips NULLs, so
     * the single-column version silently dropped every signed-out viewer the moment
     * guests started getting session rows - and folding the two columns into one
     * expression needs a cast that Postgres and MySQL spell differently. Two indexed
     * aggregates avoid both problems.
     *
     * The halves cannot overlap: a row carries a user or a guest key, never both.
     * Someone who signs in mid-session is briefly counted twice, until their guest
     * row falls outside the heartbeat window.
     */
    public static function getUserCount(): int
    {
        return Cache::remember('stream.listeners', 30, function () {
            $active = fn () => DB::table('source_users')
                ->whereNotNull('joined_at')
                ->whereNull('left_at')
                ->where('last_heartbeat_at', '>', now()->subMinutes(3));

            $users = $active()->whereNotNull('user_id')->distinct()->count('user_id');
            $guests = $active()->whereNotNull('guest_key')->distinct()->count('guest_key');

            return $users + $guests;
        });
    }

    public static function getPreviousUserCount(): int
    {
        return Cache::get('stream.listeners.old', fn () => 0);
    }

    public static function setPreviousUserCount(int $count): bool
    {
        return Cache::set('stream.listeners.old', $count);
    }
}
