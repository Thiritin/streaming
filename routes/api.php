<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

Route::middleware([\App\Http\Middleware\CheckSharedSecretMiddleware::class])->group(function () {
    Route::post('client/play', [App\Http\Controllers\Api\ClientController::class, 'play'])->name('api.client.play');
    Route::post('client/stop', [App\Http\Controllers\Api\ClientController::class, 'stop'])->name('api.client.stop');

    Route::post('stream/play', [App\Http\Controllers\Api\StreamController::class, 'play'])->name('api.stream.play');
    Route::post('stream/stop', [App\Http\Controllers\Api\StreamController::class, 'stop'])->name('api.stream.stop');

    Route::get('file/{file}', App\Http\Controllers\Api\ServerFileController::class)->name('server.file');
    
    // Server provisioning endpoints
    Route::get('server/config/{type}', [App\Http\Controllers\Api\ServerProvisionController::class, 'config'])->name('api.server.config');
    Route::get('server/scripts/{script}', [App\Http\Controllers\Api\ServerProvisionController::class, 'script'])->name('api.server.script');
    Route::post('server/register', [App\Http\Controllers\Api\ServerProvisionController::class, 'register'])->name('api.server.register');
    Route::post('server/{server}/heartbeat', [App\Http\Controllers\Api\ServerProvisionController::class, 'heartbeat'])->name('api.server.heartbeat');
});

// HLS session tracking endpoints (uses different auth)
Route::prefix('hls')->group(function () {
    Route::get('auth', [App\Http\Controllers\Api\HlsSessionController::class, 'auth'])->name('api.hls.auth');
    Route::post('session', [App\Http\Controllers\Api\HlsSessionController::class, 'createSession'])->name('api.hls.session');
    Route::post('session/start', [App\Http\Controllers\Api\HlsSessionController::class, 'sessionStart'])->name('api.hls.session.start');
    Route::post('session/heartbeat', [App\Http\Controllers\Api\HlsSessionController::class, 'sessionHeartbeat'])->name('api.hls.session.heartbeat');
    Route::post('session/end', [App\Http\Controllers\Api\HlsSessionController::class, 'sessionEnd'])->name('api.hls.session.end');
});

// SRS callbacks
Route::prefix('srs')->group(function () {
    Route::post('auth', [App\Http\Controllers\Api\SrsCallbackController::class, 'auth'])->name('api.srs.auth');
    Route::post('play', [App\Http\Controllers\Api\SrsCallbackController::class, 'play'])->name('api.srs.play');
    Route::post('stop', [App\Http\Controllers\Api\SrsCallbackController::class, 'stop'])->name('api.srs.stop');
});
