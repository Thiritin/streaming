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

Route::post('client/play', [App\Http\Controllers\Api\ClientController::class, 'play']);
Route::post('client/stop', [App\Http\Controllers\Api\ClientController::class, 'stop']);

Route::get('stream/play', [App\Http\Controllers\Api\StreamController::class, 'play']);
Route::post('stream/stop', [App\Http\Controllers\Api\StreamController::class, 'stop']);
