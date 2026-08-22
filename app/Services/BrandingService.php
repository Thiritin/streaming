<?php

namespace App\Services;

use App\Models\BrandingSetting;
use App\Support\ColorRamp;
use App\Support\Manage\Settings;
use Illuminate\Support\Facades\Storage;

/**
 * Resolves the per-installation branding: saved values from the admin panel
 * first, config/branding.php defaults second. Everything the frontend needs to
 * render a convention's own identity comes from here, so nothing about a single
 * convention has to be hardcoded in a template.
 */
class BrandingService
{
    /** Where this software lives, and under what terms. Not per-installation. */
    public const SOURCE_URL = 'https://github.com/Thiritin/streaming';

    public const LICENCE = 'GPL-3.0';

    public const LICENCE_URL = 'https://github.com/Thiritin/streaming/blob/main/LICENSE';

    /**
     * Keys editable from the admin panel, with the help text shown there.
     *
     * @var array<string, string>
     */
    public const EDITABLE = [
        'convention_name' => 'Name of the convention, used in page copy.',
        'site_name' => 'Name of this streaming site, used in the header and page titles.',
        'login_eyebrow' => 'Small label above the login headline.',
        'login_headline' => 'Main login headline.',
        'login_tagline' => 'One line under the headline.',
        'login_body' => 'Paragraph explaining what is needed to watch.',
        'login_button_label' => 'Label on the sign-in button.',
        'identity_name' => 'Name of the identity provider people sign in with.',
        'identity_register_url' => 'Where people register a new identity account.',
        'identity_logout_url' => 'Identity provider logout endpoint.',
        'footer_links' => 'Title and address for each footer link, in the order they are shown.',
        'show_source_link' => 'Whether the footer credits the project and links to its source. 1 or 0.',
        'logo_path' => 'Logo image. Leave empty to show the site name as text instead.',
        'favicon_path' => 'Tab icon. Left empty the logo is used, and with no logo the bundled mark.',
        'login_background_image' => 'Background image for the login screen.',
        'login_background_video' => 'Background video for the login screen. Left empty, the bundled clip is used.',
        'primary_color' => 'Pick a preset or a custom hex. A full 50-950 ramp is derived from it; empty keeps the palette in the stylesheet.',
    ];

    /**
     * Every resolved branding value, keyed as in config/branding.php.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $values = [];

        foreach (array_keys(config('branding')) as $key) {
            $values[$key] = BrandingSetting::getValue($key);
        }

        return $values;
    }

    public function get(string $key, $default = null)
    {
        return BrandingSetting::getValue($key, $default);
    }

    /**
     * Shape the branding for the frontend, with URLs already resolved.
     *
     * @return array<string, mixed>
     */
    public function forFrontend(): array
    {
        $values = $this->all();

        return [
            'conventionName' => $values['convention_name'],
            'siteName' => $values['site_name'],
            'logoUrl' => $this->assetUrl($values['logo_path']),
            'faviconUrl' => $this->faviconUrl(),
            'identity' => [
                'name' => $values['identity_name'],
                'registerUrl' => $values['identity_register_url'],
                'logoutUrl' => $values['identity_logout_url'],
            ],
            'login' => [
                'eyebrow' => $values['login_eyebrow'],
                'headline' => $values['login_headline'],
                'tagline' => $values['login_tagline'],
                'body' => $values['login_body'],
                'buttonLabel' => $values['login_button_label'],
                'backgroundImage' => $this->assetUrl($values['login_background_image']),
                'backgroundVideo' => $this->assetUrl($values['login_background_video']),
            ],
            // A list, not a fixed set of slots: an installation names its own
            // footer links and has as many as it likes. Empty means the footer
            // renders no link row at all.
            'links' => $this->footerLinks(),
            // The project credit in the footer. Separate from `links`, which an
            // installation owns: this one is about the software, not the event.
            'source' => $this->showSourceLink() ? [
                'url' => self::SOURCE_URL,
                'licence' => self::LICENCE,
                'licenceUrl' => self::LICENCE_URL,
            ] : null,
        ];
    }

    /**
     * The tab icon, falling back the way an installation would expect.
     *
     * A logo is usually the right mark at 16px too, so uploading one is enough; the
     * separate key exists for the installation whose logo is a wordmark that turns to
     * mush at that size. Neither set answers null, and the caller uses the bundled mark.
     */
    public function faviconUrl(): ?string
    {
        return $this->assetUrl($this->get('favicon_path'))
            ?? $this->assetUrl($this->get('logo_path'));
    }

    public function showSourceLink(): bool
    {
        return Settings::toBool($this->get('show_source_link'));
    }

    /**
     * Footer links as {label, url}, in order, with unusable rows dropped.
     *
     * @return array<int, array{label: string, url: string}>
     */
    public function footerLinks(): array
    {
        $links = [];

        foreach (Settings::decodeRows($this->get('footer_links')) as $row) {
            $label = is_array($row) ? trim((string) ($row['label'] ?? '')) : '';
            $url = is_array($row) ? trim((string) ($row['url'] ?? '')) : '';

            if ($label === '' || $url === '') {
                continue;
            }

            $links[] = ['label' => $label, 'url' => $url];
        }

        return $links;
    }

    /**
     * CSS custom properties overriding the primary ramp, empty when no accent
     * colour is configured so the stylesheet's own palette stays authoritative.
     *
     * @return array<string, string>
     */
    public function paletteVariables(): array
    {
        $accent = $this->get('primary_color');

        $variables = [];

        foreach (ColorRamp::fromHex($accent) as $stop => $value) {
            $variables["--color-primary-{$stop}"] = $value;
        }

        // The /manage chrome has its own tokens, so it needs re-tinting too or
        // the panel keeps the shipped hue while the public site changes.
        return $variables + ColorRamp::chromeFromHex($accent);
    }

    /**
     * Turn a stored path into a usable URL. Absolute URLs pass through, so an
     * installation can point at a CDN instead of uploading anything.
     *
     * Branding objects live on the bucket with public visibility, so this is a plain
     * URL rather than a signed one: the logo is on every page including the login
     * screen, and an expiring URL would both break caching and eventually 403. The
     * local `public` disk is not an option - app pods each have their own ephemeral
     * filesystem, so an upload through one replica is invisible to the rest.
     */
    protected function assetUrl(?string $path): ?string
    {
        $path = is_string($path) ? trim($path) : '';

        if ($path === '') {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://') || str_starts_with($path, '/')) {
            return $path;
        }

        // A bucket that is not configured must not take every page down with it. An
        // installation with no S3 credentials yet still renders; the logo falls back to
        // the site name as text, which is the same thing an empty path does.
        try {
            return Storage::disk('s3')->url($path);
        } catch (\Throwable) {
            return null;
        }
    }
}
