<?php

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

// up route
Route::get('/up', function () {
    return response('OK', 200);
});

Route::middleware('guest')->group(function () {
    Route::get('/auth/login', [\App\Http\Controllers\Auth\OidcClientController::class, 'login'])->name('auth.login');
    Route::get('/auth/callback', [
        \App\Http\Controllers\Auth\OidcClientController::class,
        'callback',
    ])->name('auth.callback');
    Route::get('/login', \App\Http\Controllers\Auth\LoginController::class)->name('login');
});

Route::get('/auth/frontchannel-logout', \App\Http\Controllers\Auth\FrontChannelLogoutController::class)->name('auth.frontchannel-logout');

// HLS streaming endpoints (no auth required for basic access, but streamkey validates per-user)
Route::prefix('hls')->group(function () {
    // Master playlist for adaptive bitrate streaming
    Route::get('/{stream}/master.m3u8', [\App\Http\Controllers\HlsController::class, 'master'])->name('hls.master');

    // Variant playlists (quality-specific)
    Route::get('/{variant}.m3u8', [\App\Http\Controllers\HlsController::class, 'variant'])->name('hls.variant');
});

Route::middleware(['auth:web'])->group(function () {
    Route::get('/', [\App\Http\Controllers\StreamController::class, 'index'])->name('shows.grid');
    Route::get('/shows', [\App\Http\Controllers\StreamController::class, 'index'])->name('shows.index');
    Route::get('/show/{show:slug}', [\App\Http\Controllers\StreamController::class, 'show'])->name('show.view');
    Route::get('/show/{show:slug}/external', [\App\Http\Controllers\StreamController::class, 'external'])->name('show.external');
    Route::get('/show/{show:slug}/chat', [\App\Http\Controllers\StreamController::class, 'chat'])->name('show.chat');
    Route::post('/message/send', [\App\Http\Controllers\MessageController::class, 'send'])->name('message.send');
    Route::get('/messages/older', [\App\Http\Controllers\MessageController::class, 'loadOlder'])->name('messages.older');
    Route::delete('/messages/{message}', [\App\Http\Controllers\MessageController::class, 'destroy'])->name('messages.destroy');

    // Chat moderation (mod menu + per-message quick actions)
    Route::prefix('chat')->name('chat.')->group(function () {
        Route::get('/users/{user}', [\App\Http\Controllers\Chat\ChatUserController::class, 'show'])->name('users.show');

        Route::prefix('moderation')->name('moderation.')->group(function () {
            Route::get('/', [\App\Http\Controllers\Chat\ModerationController::class, 'index'])->name('index');
            Route::post('/timeout', [\App\Http\Controllers\Chat\ModerationController::class, 'timeout'])->name('timeout');
            Route::post('/untimeout', [\App\Http\Controllers\Chat\ModerationController::class, 'untimeout'])->name('untimeout');
            Route::post('/ban', [\App\Http\Controllers\Chat\ModerationController::class, 'ban'])->name('ban');
            Route::post('/unban', [\App\Http\Controllers\Chat\ModerationController::class, 'unban'])->name('unban');
            Route::post('/purge', [\App\Http\Controllers\Chat\ModerationController::class, 'purge'])->name('purge');
            Route::post('/clear', [\App\Http\Controllers\Chat\ModerationController::class, 'clear'])->name('clear');
            Route::post('/announce', [\App\Http\Controllers\Chat\ModerationController::class, 'announce'])->name('announce');
            Route::post('/settings', [\App\Http\Controllers\Chat\ModerationController::class, 'updateSettings'])->name('settings');
        });
    });

    // Emote routes
    Route::get('/emotes', [\App\Http\Controllers\EmoteController::class, 'index'])->name('emotes.index');
    Route::post('/emotes', [\App\Http\Controllers\EmoteController::class, 'store'])->name('emotes.store');
    Route::post('/emotes/{emote}/favorite', [\App\Http\Controllers\EmoteController::class, 'toggleFavorite'])->name('emotes.favorite');
    Route::delete('/emotes/{emote}', [\App\Http\Controllers\EmoteController::class, 'destroy'])->name('emotes.destroy');

    // Programme guide
    Route::get('/schedule', [\App\Http\Controllers\ScheduleController::class, 'index'])->name('schedule.index');

    // Archive (formerly "recordings"; route names kept so existing links still resolve)
    Route::get('/archive', [\App\Http\Controllers\RecordingController::class, 'index'])->name('recordings.index');
    // Declared before /archive/{recording} so the year collection wins the match.
    Route::get('/archive/year/{year}', [\App\Http\Controllers\RecordingController::class, 'year'])
        ->whereNumber('year')
        ->name('recordings.year');
    Route::get('/archive/{recording}', [\App\Http\Controllers\RecordingController::class, 'show'])->name('recordings.show');
    Route::redirect('/recordings', '/archive');
    Route::get('/recordings/{recording}', fn ($recording) => redirect("/archive/{$recording}"));
});

Broadcast::routes();
