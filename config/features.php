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

    /*
     | Comments under a recording in the archive, with one level of replies. Off
     | hides the section and closes the endpoints; nothing already posted is
     | deleted, so switching it back on brings the threads back as they were.
     */
    'comments' => true,

    /*
     | The banner on the front page and the page behind it. Off answers 404 on
     | /announcement and shows nothing, whatever is written in the settings.
     */
    'announcement' => true,

    /*
     | Viewer feedback: the report button and the reports module in /manage. Off
     | closes the endpoint and drops the module from the panel.
     */
    'feedback' => true,

    /*
     | Unattended displays: /d, the display hub, and the Display Keys and Screens
     | modules in /manage. Off answers 404 on all of them; a screen already signed
     | in stops being served rather than being logged out.
     */
    'screens' => true,

    /*
     | The Telegram bot: notifications into linked chats, and the buttons in them
     | that start and end shows or resolve reports. Off stops every outgoing
     | message and answers 404 on the webhook, so the bot goes quiet without
     | anything being unlinked.
     */
    'telegram' => true,

    /*
     | Email and Telegram notifications to viewers. Off hides the bell, the
     | notification settings and the subscribe buttons, and stops the dispatcher
     | sending anything; nothing anybody subscribed to is forgotten, so switching
     | it back on resumes with the subscriptions intact.
     */
    'notifications' => true,

];
