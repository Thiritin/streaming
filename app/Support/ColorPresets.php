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
    /**
     * The neutral ramp in resources/css/app.css, which is what renders when no
     * accent is saved.
     *
     * Selecting it stores nothing: an empty `primary_color` means the stylesheet
     * stays authoritative, and its hand-tuned ramp is closer than anything
     * ColorRamp would derive from the 500 stop alone. The hex here is only what
     * the swatch paints itself.
     */
    public const BUILT_IN = ['value' => '', 'hex' => '#6a7282', 'label' => 'Neutral gray (built-in)'];

    public const PRESETS = [
        '#048072' => 'Deep Teal',

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
     * Presets as a list the frontend can iterate over, the built-in first so
     * getting back to the neutral default is one click rather than clearing a
     * field by hand.
     *
     * `value` is what gets stored, `hex` is what the swatch shows. They differ
     * only for the built-in, which stores nothing.
     *
     * @return array<int, array{value: string, hex: string, label: string}>
     */
    public static function forFrontend(): array
    {
        $presets = [self::BUILT_IN];

        foreach (self::PRESETS as $hex => $label) {
            $presets[] = ['value' => $hex, 'hex' => $hex, 'label' => $label];
        }

        return $presets;
    }
}
