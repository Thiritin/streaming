<?php

namespace App\Support;

/**
 * Which DNS provider this installation writes records with, and the zone it writes
 * them into. Read through here rather than off config so there is one name for the
 * question the panel asks.
 */
final class DnsSettings
{
    public static function driver(): string
    {
        return (string) config('dns.driver', 'none');
    }

    public static function zone(): string
    {
        return trim((string) config('dns.zone'), '.');
    }

    public static function ttl(): int
    {
        return (int) config('dns.ttl', 60);
    }
}
