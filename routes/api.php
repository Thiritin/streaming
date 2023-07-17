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
});
