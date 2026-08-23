<?php

namespace App\Support;

use App\Models\Event;

/**
 * The event filter, shared by every list that is read one run at a time: Shows,
 * Recordings and the recording plan.
 *
 * These lists used to be narrowed by calendar year, which is not what anybody asks
 * for - a run is what people mean when they say which year a show or a recording is
 * from, and a run that straddles New Year would be split in two by a year filter.
 *
 * Two answers are not a run and both matter: `all` switches the filter off, and
 * `none` is the pile of rows filed nowhere, which is what an archive that predates
 * the calendar looks like and the only way to find it.
 */
final class EventFilter
{
    public const ALL = 'all';

    public const NONE = 'none';

    /**
     * Every run, newest first, plus "no event". `all` is included only where the
     * list has no other way of saying it: a manage table's select already carries an
     * empty placeholder row for that.
     *
     * @return array<string, string> value => label
     */
    public static function options(bool $withAll = false): array
    {
        $options = $withAll ? [self::ALL => 'All events'] : [];

        foreach (Event::ordered()->get(['id', 'name']) as $event) {
            $options[(string) $event->id] = $event->name;
        }

        $options[self::NONE] = 'No event';

        return $options;
    }

    /**
     * What the filter sits at before anybody chooses: the run that is on, else the
     * one that just finished. An installation with no calendar falls back to the
     * given value, so it keeps the shape it had before events existed.
     */
    public static function default(string $fallback = ''): string
    {
        return (string) (Event::mostRecent()?->id ?? $fallback);
    }
}
