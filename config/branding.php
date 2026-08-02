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

    'convention_name' => env('BRANDING_CONVENTION_NAME', env('APP_NAME', 'Streaming')),

    'site_name' => env('BRANDING_SITE_NAME', env('APP_NAME', 'Streaming')),

    // Shown above the login headline. Keep it short. Empty by default: an
    // installation that has not set one gets no placeholder convention name.
    'login_eyebrow' => env('BRANDING_LOGIN_EYEBROW'),

    'login_headline' => env('BRANDING_LOGIN_HEADLINE', 'Livestream'),

    'login_tagline' => env('BRANDING_LOGIN_TAGLINE', 'Open to everyone'),

    'login_body' => env('BRANDING_LOGIN_BODY', 'Sign in to watch the live streams and recordings.'),

    'login_button_label' => env('BRANDING_LOGIN_BUTTON_LABEL', 'Sign in'),

    // Name of the OIDC provider, used in the sign-in and register wording.
    'identity_name' => env('BRANDING_IDENTITY_NAME', 'identity'),

    /*
     | Identity provider endpoints. Both are installation specific, so they stay
     | empty here: the login screen hides the register link when there is no
     | URL, and logout falls back to the local session teardown.
     */
    'identity_register_url' => env('BRANDING_IDENTITY_REGISTER_URL'),

    'identity_logout_url' => env('BRANDING_IDENTITY_LOGOUT_URL'),

    /*
     | Footer links. Each one is dropped from the footer when left empty.
     */
    'support_url' => env('BRANDING_SUPPORT_URL'),

    'imprint_url' => env('BRANDING_IMPRINT_URL'),

    'privacy_url' => env('BRANDING_PRIVACY_URL'),

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
