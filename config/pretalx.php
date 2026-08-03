<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Pretalx connection
    |--------------------------------------------------------------------------
    |
    | Defaults for the pretalx import. These are only fallbacks: the values are
    | edited at /manage > Settings and stored in the settings table, exactly like
    | branding, so an event slug can change without a deploy.
    |
    | Keys are prefixed because the settings table is one flat namespace.
    |
    */

    'pretalx_url' => null,

    'pretalx_event' => null,

    'pretalx_token' => null,

];
