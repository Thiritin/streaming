<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Feature switches
    |--------------------------------------------------------------------------
    |
    | Parts of the site an installation can turn off wholesale. Each key here is
    | the shipped default; a row in the settings table with the same key wins,
    | and that row is what /manage > Settings > Features writes.
    |
    | Read them through App\Support\Features, never through config() directly:
    | the config value is only the fallback, so a config() read would ignore
    | whatever the panel has saved.
    |
    | The CHAT_ENABLED env var is kept as the default for chat so an existing
    | deployment that switched chat off in its environment still boots with it
    | off. Saving the toggle in the panel takes over from then on.
    |
    */

    'chat' => (bool) env('CHAT_ENABLED', true),

    /*
     | Emotes in chat: the picker, the autocomplete and the inline rendering of
     | :name: in messages, plus the upload and favourite endpoints. Chat itself
     | stays up; only the images go away. Implied off when chat is off.
     */
    'emotes' => true,

    /*
     | The boop paw under the player and its shared counter.
     */
    'boops' => true,

];
