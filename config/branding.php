<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Branding defaults
    |--------------------------------------------------------------------------
    |
    | The shipped neutral defaults, and nothing else. Every value here is set per
    | installation from the admin panel (/manage > Settings), which stores what
    | you save in the branding_settings table; a key with no row falls back to
    | the literal below, so a fresh install boots with neutral copy.
    |
    | Deliberately no env() here. Branding is edited by organisers, not by ops,
    | and a second source would only be able to disagree: once a value is saved
    | in the panel it wins, so an env var that looks authoritative would quietly
    | stop applying. Scripted setup goes through `php artisan branding:set`.
    |
    | Keys are flat and dot-free on purpose: they map 1:1 onto the setting keys
    | in the database and onto the Inertia "branding" prop.
    |
    */

    'convention_name' => env('APP_NAME', 'Streaming'),

    'site_name' => env('APP_NAME', 'Streaming'),

    // Shown above the login headline. Keep it short. Empty by default: an
    // installation that has not set one gets no placeholder convention name.
    'login_eyebrow' => null,

    'login_headline' => 'Livestream',

    'login_tagline' => 'Open to everyone',

    'login_body' => 'Sign in to watch the live streams and recordings.',

    'login_button_label' => 'Sign in',

    // Name of the OIDC provider, used in the sign-in and register wording.
    'identity_name' => 'identity',

    /*
     | Identity provider endpoints. Both are installation specific, so they stay
     | empty here: the login screen hides the register link when there is no
     | URL, and logout falls back to the local session teardown.
     */
    'identity_register_url' => null,

    'identity_logout_url' => null,

    /*
     | Footer links, as a list of {label, url} in the order they are shown. Any
     | number of them, titled whatever the installation wants; empty means the
     | footer link row is not rendered at all. Stored as JSON in the settings
     | table, so this stays a plain PHP array here.
     */
    'footer_links' => [],

    /*
     | Path on the public disk to a logo image. When empty nothing is rendered
     | in its place and callers fall back to the site name in text.
     */
    'logo_path' => null,

    /*
     | Background media for the login screen. The image is used as the video
     | poster, so it is what visitors see before the clip has buffered.
     */
    'login_background_image' => null,

    /*
     | Left empty, the login screen falls back to the background clip bundled
     | with the frontend assets.
     */
    'login_background_video' => null,

    /*
     | Base accent colour as a hex string. When set, a 50-950 ramp is derived
     | from it at runtime and injected as CSS custom properties, overriding the
     | --color-primary-* defaults in resources/css/app.css. No rebuild involved.
     | Leave empty to use the ramp shipped in the stylesheet untouched.
     */
    'primary_color' => null,

];
