<?php

use App\Http\Controllers\HlsController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| HLS Playlist Routes
|--------------------------------------------------------------------------
|
| Playlists are polled by every viewer every couple of seconds, which makes
| this the one path in the app where the middleware stack is worth counting.
| It runs on the `hls` group rather than `web`: cookies and a session, so a
| signed-in viewer is recognised and a guest keeps their viewer id, and
| nothing else. See App\Http\Kernel.
|
| No auth middleware. What a caller may see is decided in the controller,
| where a streamkey, a session or an embed token are all accepted.
|
*/

// Master playlist for adaptive bitrate streaming
Route::get('/hls/{stream}/master.m3u8', [HlsController::class, 'master'])->name('hls.master');

// Variant playlists (quality-specific)
Route::get('/hls/{variant}.m3u8', [HlsController::class, 'variant'])->name('hls.variant');
