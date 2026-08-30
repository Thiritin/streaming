<?php

namespace App\Support;

use App\Models\BrandingSetting;

/**
 * The knobs on viewer notifications.
 *
 * Same rule as the control and import keys: the settings table is the one source and
 * config/notifications.php only names the shipped fallback, so never read the config
 * key directly.
 */
final class NotificationSettings
{
    public const DELAY_KEY = 'notification_delay_hours';

    /**
     * Clamped rather than trusted. Zero would mail out a recording the instant somebody
     * fat-fingered the publish switch, which is the one thing the delay exists to
     * prevent; the ceiling stops a typo from parking the whole archive indefinitely.
     */
    public static function delayHours(): int
    {
        $hours = (int) BrandingSetting::getValue(self::DELAY_KEY, config('notifications.delay_hours', 4));

        return max(1, min(168, $hours ?: 4));
    }

    public static function catchUpDays(): int
    {
        return max(1, (int) config('notifications.catch_up_days', 7));
    }
}
