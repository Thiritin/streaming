<?php

namespace App\Services;

use App\Models\BrandingSetting;
use App\Support\ColorRamp;
use Illuminate\Support\Facades\Storage;

/**
 * Resolves the per-installation branding: saved values from the admin panel
 * first, config/branding.php defaults second. Everything the frontend needs to
 * render a convention's own identity comes from here, so nothing about a single
 * convention has to be hardcoded in a template.
 */
class BrandingService
{
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
        'support_url' => 'Support link in the footer.',
        'imprint_url' => 'Legal Notice link in the footer.',
        'privacy_url' => 'Privacy link in the footer.',
        'logo_path' => 'Logo image. Leave empty to use the built-in mark.',
        'login_background_image' => 'Background image for the login screen.',
        'login_background_video' => 'Background video for the login screen. Left empty, the bundled clip is used.',
        'primary_color' => 'Base accent colour. A full 50-950 ramp is derived from it. Empty keeps the built-in palette.',
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
            'links' => [
                'support' => $values['support_url'],
                'imprint' => $values['imprint_url'],
                'privacy' => $values['privacy_url'],
            ],
        ];
    }

    /**
     * CSS custom properties overriding the primary ramp, empty when no accent
     * colour is configured so the stylesheet's own palette stays authoritative.
     *
     * @return array<string, string>
     */
    public function paletteVariables(): array
    {
        $ramp = ColorRamp::fromHex($this->get('primary_color'));

        $variables = [];

        foreach ($ramp as $stop => $value) {
            $variables["--color-primary-{$stop}"] = $value;
        }

        return $variables;
    }

    /**
     * Turn a stored path into a usable URL. Absolute URLs pass through, so an
     * installation can point at a CDN instead of uploading anything.
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

        return Storage::disk('public')->url($path);
    }
}
