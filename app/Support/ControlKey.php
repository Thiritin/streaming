<?php

namespace App\Support;

use App\Models\BrandingSetting;

/**
 * The key a control surface authenticates with.
 *
 * Saved at /manage > Settings > Control surfaces, which is where it is rotated. The
 * `COMPANION_API_KEY` environment variable is only the fallback a fresh install boots
 * with, so a saved row always wins; clearing the field hands the key back to the
 * environment rather than switching the control API off on a host that sets one.
 *
 * Empty either way means the control API is off, which is the state of a fresh install.
 */
final class ControlKey
{
    public static function current(): string
    {
        return trim((string) BrandingSetting::getValue('control_key', config('stream.control_key')));
    }
}
