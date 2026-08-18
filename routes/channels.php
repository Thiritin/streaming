<?php

use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Here you may register all of the event broadcasting channels that your
| application supports. The given channel authorization callbacks are
| used to check if an authenticated user can listen to the channel.
|
*/

Broadcast::channel('user.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Moderator-only side channel for a chat source. Carries the notices that would
// otherwise announce every punishment to the whole room.
Broadcast::channel('chat.source.{sourceId}.mods', function ($user) {
    return $user->canModerateChat();
});

// Private channels only - public channels don't need to be defined here
