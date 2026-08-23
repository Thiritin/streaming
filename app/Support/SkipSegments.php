<?php

namespace App\Support;

/**
 * The stretches of a recording a viewer can be offered a way past.
 *
 * A skip is an offer and never an edit: the media is untouched, the player simply
 * shows a button while the playhead is inside one, and only a press moves it. That
 * is the whole reason these are ranges rather than cut points - somebody who wants
 * to watch the intermission still can.
 *
 * Everything is seconds from the start of the recording. Overlapping ranges are
 * merged on the way in: two buttons for one moment has no sensible answer, and an
 * operator marking the same gap twice is the ordinary way to arrive there.
 */
class SkipSegments
{
    public const MAX = 20;

    public const LABEL_MAX = 40;

    /**
     * @param  mixed  $segments
     * @return array<int, array{start: int, end: int, label: string|null}>
     */
    public static function normalise($segments, ?int $duration = null): array
    {
        if (! is_array($segments)) {
            return [];
        }

        $clean = [];

        foreach ($segments as $segment) {
            if (! is_array($segment)) {
                continue;
            }

            $start = (int) round((float) ($segment['start'] ?? 0));
            $end = (int) round((float) ($segment['end'] ?? 0));

            if ($duration !== null && $duration > 0) {
                $start = min($start, $duration);
                $end = min($end, $duration);
            }

            $start = max(0, $start);
            $end = max(0, $end);

            // A zero-length range would put up a button that does nothing.
            if ($end <= $start) {
                continue;
            }

            $label = trim((string) ($segment['label'] ?? ''));

            $clean[] = [
                'start' => $start,
                'end' => $end,
                'label' => $label === '' ? null : mb_substr($label, 0, self::LABEL_MAX),
            ];
        }

        usort($clean, fn (array $a, array $b) => $a['start'] <=> $b['start']);

        $merged = [];

        foreach ($clean as $segment) {
            $last = end($merged);

            if ($last !== false && $segment['start'] <= $last['end']) {
                $index = array_key_last($merged);
                $merged[$index]['end'] = max($last['end'], $segment['end']);
                $merged[$index]['label'] = $last['label'] ?? $segment['label'];

                continue;
            }

            $merged[] = $segment;
        }

        return array_slice($merged, 0, self::MAX);
    }
}
