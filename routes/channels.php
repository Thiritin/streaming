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

Broadcast::channel('User.{id}.StreamUrl', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('Client.{id}', function ($user, $id) {
    $client = \App\Models\Client::with('serverUser')->where('id', $id)->firstOrFail();
    return (int) $user->id === (int) $client->serverUser->user_id;
});

Broadcast::channel('StreamInfo', function () {
    return Auth::check();
});
