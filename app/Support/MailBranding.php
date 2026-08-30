<?php

namespace App\Support;

use App\Services\BrandingService;

/**
 * The installation's identity, flattened into what an email can actually use.
 *
 * The site paints itself with CSS custom properties and an OKLCH ramp, neither of
 * which survives a mail client: everything here is a plain hex string, mixed in sRGB,
 * ready to be written into a `style` attribute. The accent is the same one saved in
 * /manage > Settings > Look, so an installation that rebrands the site rebrands its
 * mail without touching a template.
 *
 * The footer links are the same list the login page and the site footer render, for
 * the same reason a receipt carries an imprint: whatever an installation is obliged
 * to show, it is obliged to show in what it sends out too.
 */
final class MailBranding
{
    /**
     * The near-neutral slate the stylesheet ships as --color-primary-500, as hex. What
     * an installation that has never picked an accent gets.
     */
    private const FALLBACK_ACCENT = '#64748b';

    /**
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        $branding = app(BrandingService::class);
        $values = $branding->all();

        $accent = self::normaliseHex($values['primary_color'] ?? null) ?? self::FALLBACK_ACCENT;

        return [
            'siteName' => $values['site_name'],
            'conventionName' => $values['convention_name'],
            'logoUrl' => self::absolute($branding->forFrontend()['logoUrl'] ?? null),
            'links' => $branding->footerLinks(),
            'source' => $branding->showSourceLink() ? [
                'url' => BrandingService::SOURCE_URL,
                'licence' => BrandingService::LICENCE,
            ] : null,
            'palette' => self::palette($accent),
        ];
    }

    /**
     * Every colour the templates use, keyed by role rather than by shade: a template
     * that asks for `border` keeps working when the accent changes, and one that asks
     * for `primary-700` does not.
     *
     * @return array<string, string>
     */
    public static function palette(string $accent): array
    {
        return [
            'accent' => $accent,
            'accentText' => self::readableOn($accent),
            'page' => self::shade($accent, 0.90),
            'card' => self::shade($accent, 0.82),
            'raised' => self::shade($accent, 0.76),
            'border' => self::shade($accent, 0.66),
            'heading' => self::tint($accent, 0.92),
            'text' => self::tint($accent, 0.74),
            'muted' => self::tint($accent, 0.44),
        ];
    }

    /**
     * A mail client cannot resolve `/storage/logo.png`, so anything root-relative is
     * made absolute against APP_URL before it goes out.
     */
    private static function absolute(?string $url): ?string
    {
        if (! is_string($url) || trim($url) === '') {
            return null;
        }

        $url = trim($url);

        return str_starts_with($url, '/') ? rtrim((string) config('app.url'), '/').$url : $url;
    }

    private static function normaliseHex(?string $hex): ?string
    {
        $hex = is_string($hex) ? trim($hex) : '';

        if (! preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $hex)) {
            return null;
        }

        if (mb_strlen($hex) === 4) {
            $hex = '#'.$hex[1].$hex[1].$hex[2].$hex[2].$hex[3].$hex[3];
        }

        return mb_strtolower($hex);
    }

    /** Toward white. */
    private static function tint(string $hex, float $amount): string
    {
        return self::mix($hex, [255, 255, 255], $amount);
    }

    /** Toward black. */
    private static function shade(string $hex, float $amount): string
    {
        return self::mix($hex, [0, 0, 0], $amount);
    }

    /**
     * @param  array{int, int, int}  $target
     */
    private static function mix(string $hex, array $target, float $amount): string
    {
        [$r, $g, $b] = self::toRgb($hex);
        $amount = max(0.0, min(1.0, $amount));

        return sprintf(
            '#%02x%02x%02x',
            (int) round($r + ($target[0] - $r) * $amount),
            (int) round($g + ($target[1] - $g) * $amount),
            (int) round($b + ($target[2] - $b) * $amount),
        );
    }

    /**
     * Black or white, whichever can be read on this colour. A bright accent with white
     * text on it is the single most common way a rebranded button becomes unreadable.
     */
    private static function readableOn(string $hex): string
    {
        [$r, $g, $b] = self::toRgb($hex);

        $luminance = (0.2126 * $r + 0.7152 * $g + 0.0722 * $b) / 255;

        return $luminance > 0.6 ? '#0b0d10' : '#ffffff';
    }

    /**
     * @return array{int, int, int}
     */
    private static function toRgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        ];
    }
}
