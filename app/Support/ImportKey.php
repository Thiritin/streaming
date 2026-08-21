<?php

namespace App\Support;

use App\Models\BrandingSetting;

/**
 * The key an archive import authenticates with.
 *
 * One source: the settings table, generated and rotated at /manage > Settings > Imports.
 * Deliberately no environment variable behind it, for the same reason the control key has
 * none - a second source could only ever disagree with the row the panel shows - and
 * because the point of moving it here is that handing someone an import key should not
 * mean handing them a secret out of the cluster.
 *
 * Nothing saved means importing is off, which is the state of a fresh install.
 *
 * Not to be confused with RECORDING_API_KEY, which still guards the older
 * /api/recording/shows and /api/recording/create endpoints. That one predates the settings
 * registry and stays an env var until whatever external caller might still use it is known
 * to be gone.
 */
final class ImportKey
{
    public const KEY = 'import_key';

    public static function current(): string
    {
        return trim((string) BrandingSetting::getValue(self::KEY));
    }
}
