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

// Command API endpoints
Route::middleware(['web','auth','throttle:60,60'])->prefix('command')->group(function () {
    Route::post('/execute', [App\Http\Controllers\Api\CommandController::class, 'execute'])->name('api.command.execute');
    Route::get('/suggestions', [App\Http\Controllers\Api\CommandController::class, 'suggestions'])->name('api.command.suggestions');
    Route::get('/list', [App\Http\Controllers\Api\CommandController::class, 'list'])->name('api.command.list');
    Route::get('/search', [App\Http\Controllers\Api\CommandController::class, 'search'])->name('api.command.search');
    Route::get('/help', [App\Http\Controllers\Api\CommandController::class, 'help'])->name('api.command.help');
});

Route::middleware([\App\Http\Middleware\CheckSharedSecretMiddleware::class])->group(function () {
    Route::post('stream/play', [App\Http\Controllers\Api\StreamController::class, 'play'])->name('api.stream.play');
    Route::post('stream/stop', [App\Http\Controllers\Api\StreamController::class, 'stop'])->name('api.stream.stop');

    Route::get('file/{file}', App\Http\Controllers\Api\ServerFileController::class)->name('server.file');

    // Server provisioning endpoints
    Route::get('server/config/{type}', [App\Http\Controllers\Api\ServerProvisionController::class, 'config'])->name('api.server.config');
    Route::get('server/scripts/{script}', [App\Http\Controllers\Api\ServerProvisionController::class, 'script'])->name('api.server.script');
    Route::post('server/register', [App\Http\Controllers\Api\ServerProvisionController::class, 'register'])->name('api.server.register');
    Route::post('server/{server}/heartbeat', [App\Http\Controllers\Api\ServerProvisionController::class, 'heartbeat'])->name('api.server.heartbeat');
});

// HLS session tracking endpoints
Route::prefix('hls')->group(function () {
    Route::get('auth', [App\Http\Controllers\Api\HlsSessionController::class, 'auth'])->name('api.hls.auth');
    Route::post('heartbeat', [App\Http\Controllers\Api\HlsSessionController::class, 'heartbeat'])->name('api.hls.heartbeat');
});

// SRS callbacks
Route::prefix('srs')->group(function () {
    Route::post('auth', [App\Http\Controllers\Api\SrsCallbackController::class, 'auth'])->name('api.srs.auth');
    Route::post('play', [App\Http\Controllers\Api\SrsCallbackController::class, 'play'])->name('api.srs.play');
    Route::post('stop', [App\Http\Controllers\Api\SrsCallbackController::class, 'stop'])->name('api.srs.stop');
    Route::post('unpublish', [App\Http\Controllers\Api\SrsCallbackController::class, 'unpublish'])->name('api.srs.unpublish');
    Route::post('error', [App\Http\Controllers\Api\SrsCallbackController::class, 'error'])->name('api.srs.error');
    Route::post('on-hls', [App\Http\Controllers\Api\SrsCallbackController::class, 'onHls'])->name('api.srs.on-hls');
    Route::post('on-play', [App\Http\Controllers\Api\SrsCallbackController::class, 'play'])->name('api.srs.on-play');
    Route::post('on-stop', [App\Http\Controllers\Api\SrsCallbackController::class, 'stop'])->name('api.srs.on-stop');
    Route::post('dvr', [App\Http\Controllers\Api\SrsDvrController::class, 'handleDvrCallback'])->name('api.srs.dvr');
});

// DVR uploader webhooks
Route::post('dvr/upload-webhook', [App\Http\Controllers\Api\SrsDvrController::class, 'handleUploadWebhook'])->name('api.dvr.upload-webhook');
