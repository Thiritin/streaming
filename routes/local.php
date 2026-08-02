<?php

use App\Http\Controllers\Local\DebugController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Local Development Routes
|--------------------------------------------------------------------------
|
| Registered by RouteServiceProvider only when `app()->isLocal()`, and gated
| again at runtime by the LocalOnly middleware. These bypass authentication on
| purpose, so they must never be reachable in any deployed environment.
|
*/

Route::prefix('debug')->name('debug.')->group(function () {
    Route::get('/', [DebugController::class, 'index'])->name('index');
    Route::post('/login/{user}', [DebugController::class, 'loginAs'])->name('login');
    Route::post('/persona', [DebugController::class, 'persona'])->name('persona');
    Route::post('/reset', [DebugController::class, 'reset'])->name('reset');
    Route::post('/logout', [DebugController::class, 'logout'])->name('logout');
});
