<?php

use App\Http\Controllers\ProfileController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

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

Route::get('/error/no-valid-ticket', \App\Http\Controllers\Auth\NoValidTicketController::class)
    ->name('error.no-valid-ticket');


Route::get('/auth/frontchannel-logout', \App\Http\Controllers\Auth\FrontChannelLogoutController::class)->name('auth.frontchannel-logout');

Route::middleware(['auth:web'])->group(function () {
    Route::get('/', [\App\Http\Controllers\StreamController::class,'online'])->name('dashboard');
    Route::get('/external-stream', [\App\Http\Controllers\StreamController::class,'external'])->name('external-stream');
    Route::post('/message/send', [\App\Http\Controllers\MessageController::class,'send'])->name('message.send');

});

Broadcast::routes();
