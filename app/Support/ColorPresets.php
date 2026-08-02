<?php

namespace App\Support;

/**
 * Named accent colours offered next to the colour picker in /manage settings.
 *
 * Each value is the 500 stop of a Tailwind palette, which is what
 * {@see ColorRamp} expects: it takes the hue and chroma from here and walks
 * lightness across the 50-950 stops. Picking from this list rather than typing a
 * hex keeps installations on colours that are known to produce a clean ramp.
 */
final class ColorPresets
{
    /**
     * Preset label keyed by hex.
     *
     * @var array<string, string>
     */
    public const PRESETS = [
        // The ramp shipped in resources/css/app.css, offered by name so an
        // installation can get back to it after trying something else.
        '#048072' => 'Deep Teal (built-in)',

        '#ef4444' => 'Red',
        '#f97316' => 'Orange',
        '#f59e0b' => 'Amber',
        '#eab308' => 'Yellow',
        '#84cc16' => 'Lime',
        '#22c55e' => 'Green',
        '#10b981' => 'Emerald',
        '#14b8a6' => 'Teal',
        '#06b6d4' => 'Cyan',
        '#0ea5e9' => 'Sky',
        '#3b82f6' => 'Blue',
        '#6366f1' => 'Indigo',
        '#8b5cf6' => 'Violet',
        '#a855f7' => 'Purple',
        '#d946ef' => 'Fuchsia',
        '#ec4899' => 'Pink',
        '#f43f5e' => 'Rose',
        '#64748b' => 'Slate',
    ];

    /**
     * Presets as a list the frontend can iterate over.
     *
     * @return array<int, array{hex: string, label: string}>
     */
    public static function forFrontend(): array
    {
        $presets = [];

        foreach (self::PRESETS as $hex => $label) {
            $presets[] = ['hex' => $hex, 'label' => $label];
        }

        return $presets;
    }
}
