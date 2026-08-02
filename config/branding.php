<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Branding defaults
    |--------------------------------------------------------------------------
    |
    | Every value here can be overridden per installation from the admin panel
    | (Streaming > Branding), which stores changes in the branding_settings
    | table. These are the fallbacks used when no override has been saved, so a
    | fresh install of the streaming system boots with sensible neutral copy.
    |
    | Keys are flat and dot-free on purpose: they map 1:1 onto the setting keys
    | in the database and onto the Inertia "branding" prop.
    |
    */

    'convention_name' => env('BRANDING_CONVENTION_NAME', 'Eurofurence'),

    'site_name' => env('BRANDING_SITE_NAME', 'Eurofurence Streaming'),

    // Shown above the login headline. Keep it short.
    'login_eyebrow' => env('BRANDING_LOGIN_EYEBROW', 'Eurofurence'),

    'login_headline' => env('BRANDING_LOGIN_HEADLINE', 'Livestream'),

    'login_tagline' => env('BRANDING_LOGIN_TAGLINE', 'Open to everyone'),

    'login_body' => env('BRANDING_LOGIN_BODY', 'All you need is a free Eurofurence Identity account to view live streams and recordings. No ticket and no membership required.'),

    'login_button_label' => env('BRANDING_LOGIN_BUTTON_LABEL', 'Sign in'),

    // Name of the OIDC provider, used in the sign-in and register wording.
    'identity_name' => env('BRANDING_IDENTITY_NAME', 'Eurofurence Identity'),

    'identity_register_url' => env('BRANDING_IDENTITY_REGISTER_URL', 'https://identity.eurofurence.org/auth/register'),

    'identity_logout_url' => env('BRANDING_IDENTITY_LOGOUT_URL', 'https://identity.eurofurence.org/oauth2/sessions/logout'),

    'support_url' => env('BRANDING_SUPPORT_URL', 'https://help.eurofurence.org/contact/'),

    'imprint_url' => env('BRANDING_IMPRINT_URL', 'https://help.eurofurence.org/legal/imprint'),

    'privacy_url' => env('BRANDING_PRIVACY_URL', 'https://help.eurofurence.org/legal/privacy'),

    /*
     | Path on the public disk to a logo image. When empty the built-in logo
     | component falls back to its bundled SVG mark.
     */
    'logo_path' => env('BRANDING_LOGO_PATH'),

    /*
     | Background media for the login screen. The image is used as the video
     | poster, so it is what visitors see before the clip has buffered.
     */
    'login_background_image' => env('BRANDING_LOGIN_BACKGROUND_IMAGE'),

    /*
     | Left empty, the login screen falls back to the background clip bundled
     | with the frontend assets.
     */
    'login_background_video' => env('BRANDING_LOGIN_BACKGROUND_VIDEO'),

    /*
     | Base accent colour as a hex string. When set, a 50-950 ramp is derived
     | from it at runtime and injected as CSS custom properties, overriding the
     | --color-primary-* defaults in resources/css/app.css. Leave empty to use
     | the ramp shipped in the stylesheet untouched.
     */
    'primary_color' => env('BRANDING_PRIMARY_COLOR'),

];
