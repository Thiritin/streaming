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

Route::middleware('guest')->group(function () {
    Route::get('/auth/login', [\App\Http\Controllers\Auth\OidcClientController::class, 'login'])->name('auth.login');
    Route::get('/auth/callback', [
        \App\Http\Controllers\Auth\OidcClientController::class,
        'callback',
    ])->name('auth.callback');
    Route::get('/login', \App\Http\Controllers\Auth\LoginController::class)->name('login');
});

Route::get('/auth/frontchannel-logout', \App\Http\Controllers\Auth\FrontChannelLogoutController::class)->name('auth.frontchannel-logout');

Route::middleware(['auth:web', 'ensure.server'])->group(function () {
    // Provisioning wait page - middleware allows this route even without server
    Route::get('/provisioning/wait', [\App\Http\Controllers\ProvisioningController::class, 'wait'])->name('provisioning.wait');

    // All other authenticated routes require server assignment
    Route::get('/', [\App\Http\Controllers\StreamController::class, 'index'])->name('shows.grid');
    Route::get('/shows', [\App\Http\Controllers\StreamController::class, 'index'])->name('shows.index');
    Route::get('/show/{show:slug}', [\App\Http\Controllers\StreamController::class, 'show'])->name('show.view');
    Route::get('/show/{show:slug}/external', [\App\Http\Controllers\StreamController::class, 'external'])->name('show.external');
    Route::post('/message/send', [\App\Http\Controllers\MessageController::class, 'send'])->name('message.send');

    // Source heartbeat endpoint
    Route::post('/sources/{source}/heartbeat', [\App\Http\Controllers\SourceHeartbeatController::class, 'heartbeat'])->name('source.heartbeat');

    // Emote routes
    Route::get('/emotes', [\App\Http\Controllers\EmoteController::class, 'index'])->name('emotes.index');
    Route::post('/emotes', [\App\Http\Controllers\EmoteController::class, 'store'])->name('emotes.store');
    Route::post('/emotes/{emote}/favorite', [\App\Http\Controllers\EmoteController::class, 'toggleFavorite'])->name('emotes.favorite');
    Route::delete('/emotes/{emote}', [\App\Http\Controllers\EmoteController::class, 'destroy'])->name('emotes.destroy');
});

Broadcast::routes();
