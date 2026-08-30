<?php

namespace App\Support;

/**
 * Which provider this installation builds servers with. Read through here rather than
 * off config so there is one name for the question the panel asks.
 */
final class CloudSettings
{
    public const HETZNER = 'hetzner';

    public const MANUAL = 'manual';

    public static function driver(): string
    {
        return (string) config('stream.server.provider', self::MANUAL);
    }
}
