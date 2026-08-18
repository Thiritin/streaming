<?php

namespace App\Support;

use App\Models\BrandingSetting;

/**
 * The key a control surface authenticates with.
 *
 * One source: the settings table, generated and rotated at /manage > Settings >
 * Control surfaces. There is deliberately no environment variable behind it, because a
 * second source could only ever disagree with the row the panel shows.
 *
 * Nothing saved means the control API is off, which is the state of a fresh install.
 */
final class ControlKey
{
    public const KEY = 'control_key';

    public static function current(): string
    {
        return trim((string) BrandingSetting::getValue(self::KEY));
    }
}
