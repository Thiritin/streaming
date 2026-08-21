<?php

namespace App\Support;

/**
 * Bounds the diagnostics blob a browser sends with a report.
 *
 * The client decides what is worth reporting, which is the only way this keeps
 * working as the player changes. That means the payload is attacker-controlled, so
 * nothing here trusts its shape: depth, breadth and value length are all capped, and
 * anything that is not a scalar or a list/map of scalars is dropped rather than
 * stored. What survives is safe to hand to `json_encode` and safe to print in
 * /manage.
 */
final class Diagnostics
{
    private const MAX_DEPTH = 4;

    private const MAX_KEYS = 60;

    private const MAX_VALUE = 500;

    private const MAX_KEY = 64;

    /**
     * @param  array<mixed>  $raw
     * @return array<string, mixed>
     */
    public static function sanitize(array $raw): array
    {
        $clean = self::walk($raw, 1);

        return is_array($clean) ? $clean : [];
    }

    /**
     * What the server knows better than the browser does, merged over whatever the
     * browser reported under the same names.
     *
     * @param  array<string, mixed>  $client
     * @return array<string, mixed>
     */
    public static function withServerFacts(array $client, array $facts): array
    {
        return array_merge($client, self::sanitize($facts));
    }

    private static function walk(mixed $value, int $depth): mixed
    {
        if (is_array($value)) {
            if ($depth > self::MAX_DEPTH) {
                return null;
            }

            $out = [];

            foreach ($value as $key => $item) {
                if (count($out) >= self::MAX_KEYS) {
                    break;
                }

                $clean = self::walk($item, $depth + 1);

                if ($clean === null || $clean === [] || $clean === '') {
                    continue;
                }

                $out[self::key((string) $key)] = $clean;
            }

            return $out;
        }

        return self::scalar($value);
    }

    private static function scalar(mixed $value): string|int|float|bool|null
    {
        if (is_bool($value) || is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            // NAN and INF are valid floats and invalid JSON, and a browser reporting a
            // buffer length before playback starts produces both.
            return is_finite($value) ? round($value, 3) : null;
        }

        if (is_string($value)) {
            $trimmed = trim($value);

            return $trimmed === '' ? null : mb_substr($trimmed, 0, self::MAX_VALUE);
        }

        return null;
    }

    private static function key(string $key): string
    {
        $clean = preg_replace('/[^A-Za-z0-9 _.\-]/', '', $key) ?? '';

        return mb_substr($clean === '' ? 'field' : $clean, 0, self::MAX_KEY);
    }
}
