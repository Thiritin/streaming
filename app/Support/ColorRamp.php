<?php

namespace App\Support;

/**
 * Derives a Tailwind-style 50-950 ramp from a single hex accent colour.
 *
 * The stylesheet ships its ramp as OKLCH, so we convert once to OKLCH and then
 * walk lightness and chroma across the stops while holding the hue. That keeps
 * a rebranded palette as evenly spaced as the hand-tuned default instead of the
 * muddy midtones you get from mixing toward black and white in sRGB.
 */
class ColorRamp
{
    /**
     * Lightness (%) and a chroma multiplier per stop, matched to the spacing of
     * the default ramp in resources/css/app.css.
     */
    private const STOPS = [
        '50' => [94.77, 0.78],
        '100' => [89.50, 1.66],
        '200' => [80.03, 1.48],
        '300' => [71.68, 1.32],
        '400' => [62.48, 1.16],
        '500' => [53.86, 1.00],
        '600' => [45.51, 0.84],
        '700' => [38.07, 0.71],
        '800' => [29.53, 0.55],
        '900' => [21.87, 0.41],
        '950' => [17.17, 0.32],
    ];

    /**
     * Ceiling on the base chroma before it is scaled across the stops. A very
     * saturated accent multiplied up for the light stops lands outside sRGB,
     * where the browser gamut-maps it and the ramp stops being evenly spaced.
     * Clamping the base keeps the shape of the ramp intact instead.
     */
    private const MAX_BASE_CHROMA = 0.13;

    /**
     * @return array<string, string> stop => oklch() string, empty when unparseable
     */
    public static function fromHex(?string $hex): array
    {
        $rgb = self::hexToRgb($hex);

        if ($rgb === null) {
            return [];
        }

        [, $chroma, $hue] = self::rgbToOklch($rgb);

        $chroma = min($chroma, self::MAX_BASE_CHROMA);

        // A greyscale accent has no meaningful hue; keep it grey rather than
        // inventing one from floating point noise.
        if ($chroma < 0.002) {
            $chroma = 0.0;
            $hue = 0.0;
        }

        $ramp = [];

        foreach (self::STOPS as $stop => [$lightness, $chromaScale]) {
            $stopChroma = round($chroma * $chromaScale, 4);

            $ramp[$stop] = sprintf('oklch(%s%% %s %s)', round($lightness, 2), $stopChroma, round($hue, 2));
        }

        return $ramp;
    }

    /**
     * @return array{0: float, 1: float, 2: float}|null 0-1 sRGB components
     */
    private static function hexToRgb(?string $hex): ?array
    {
        if (! is_string($hex)) {
            return null;
        }

        $hex = ltrim(trim($hex), '#');

        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        if (! preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
            return null;
        }

        return [
            hexdec(substr($hex, 0, 2)) / 255,
            hexdec(substr($hex, 2, 2)) / 255,
            hexdec(substr($hex, 4, 2)) / 255,
        ];
    }

    /**
     * @param  array{0: float, 1: float, 2: float}  $rgb
     * @return array{0: float, 1: float, 2: float} lightness %, chroma, hue deg
     */
    private static function rgbToOklch(array $rgb): array
    {
        [$r, $g, $b] = array_map([self::class, 'toLinear'], $rgb);

        // sRGB -> LMS (Björn Ottosson's Oklab matrices)
        $l = 0.4122214708 * $r + 0.5363325363 * $g + 0.0514459929 * $b;
        $m = 0.2119034982 * $r + 0.6806995451 * $g + 0.1073969566 * $b;
        $s = 0.0883024619 * $r + 0.2817188376 * $g + 0.6299787005 * $b;

        $l = self::cbrt($l);
        $m = self::cbrt($m);
        $s = self::cbrt($s);

        $labL = 0.2104542553 * $l + 0.7936177850 * $m - 0.0040720468 * $s;
        $labA = 1.9779984951 * $l - 2.4285922050 * $m + 0.4505937099 * $s;
        $labB = 0.0259040371 * $l + 0.7827717662 * $m - 0.8086757660 * $s;

        $chroma = sqrt($labA ** 2 + $labB ** 2);
        $hue = atan2($labB, $labA) * 180 / M_PI;

        if ($hue < 0) {
            $hue += 360;
        }

        return [$labL * 100, $chroma, $hue];
    }

    private static function toLinear(float $channel): float
    {
        return $channel <= 0.04045
            ? $channel / 12.92
            : (($channel + 0.055) / 1.055) ** 2.4;
    }

    private static function cbrt(float $value): float
    {
        return $value < 0 ? -((-$value) ** (1 / 3)) : $value ** (1 / 3);
    }
}
