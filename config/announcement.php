<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Site announcement
    |--------------------------------------------------------------------------
    |
    | One banner on the front page, for the thing everybody watching needs to know
    | right now, with the full text behind it at /announcement. Edited at /manage >
    | Settings > Announcement, which stores what you save in the settings table; a
    | key with no row falls back to the value here.
    |
    | No env(), for the same reason branding has none: a saved row always wins, so
    | a second source could only disagree.
    |
    */

    'announcement_enabled' => false,

    // Optional bold line above the body.
    'announcement_title' => null,

    // The banner line. Markdown; empty means no banner, whatever the toggle says.
    'announcement_body' => null,

    // One of App\Support\Announcement::LEVELS. Decides the colour only.
    'announcement_level' => 'info',

    // The whole announcement, read at /announcement. Empty means no page and no link.
    'announcement_details' => null,

    // Somewhere other than the built-in page to send people. Wins over it when set.
    'announcement_link_url' => null,

    // What the read-more link is called, wherever it points. Empty reads "Read more".
    'announcement_link_label' => null,

    // Whether a viewer can close it. A dismissal lasts until the text is edited.
    'announcement_dismissible' => true,

];
