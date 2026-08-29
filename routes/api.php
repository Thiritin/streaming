<?php

use App\Http\Controllers\Api\ArchiveImportController;
use App\Http\Controllers\Api\CommandController;
use App\Http\Controllers\Api\CompanionController;
use App\Http\Controllers\Api\HlsSessionController;
use App\Http\Controllers\Api\RecordingApiController;
use App\Http\Controllers\Api\ServerProvisionController;
use App\Http\Controllers\Api\SrsCallbackController;
use App\Http\Controllers\Api\StreamController;
use App\Http\Controllers\Api\TelegramWebhookController;
use App\Http\Middleware\CheckCompanionTokenMiddleware;
use App\Http\Middleware\CheckImportKeyMiddleware;
use App\Http\Middleware\CheckRecordingApiKeyMiddleware;
use App\Http\Middleware\CheckSharedSecretMiddleware;
use App\Http\Middleware\CheckSrsCallbackMiddleware;
use App\Http\Middleware\CheckTelegramWebhookMiddleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
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

// Command API endpoints. These are chat commands, so they go away with chat.
Route::middleware(['web', 'auth', 'chat.enabled', 'throttle:60,60'])->prefix('command')->group(function () {
    Route::post('/execute', [CommandController::class, 'execute'])->name('api.command.execute');
    Route::get('/suggestions', [CommandController::class, 'suggestions'])->name('api.command.suggestions');
    Route::get('/list', [CommandController::class, 'list'])->name('api.command.list');
    Route::get('/search', [CommandController::class, 'search'])->name('api.command.search');
    Route::get('/help', [CommandController::class, 'help'])->name('api.command.help');
});

// Everything a streaming server asks the app for. The server is in the path on every one
// of them, which is what lets CheckSharedSecretMiddleware resolve the row first and check
// the presented credential against that row - see the middleware for why the reverse was
// a hole. Header only; a credential in a query string is a credential in an access log.
// The throttle sits in front of the check rather than behind it, so a wrong credential
// is counted too - otherwise guessing at one would be the one thing not rate limited.
// Route binding is off here on purpose: it answers 404 for an id that does not exist,
// and these endpoints serve credentials, so every refusal is the same 401 instead. The
// middleware resolves the row itself.
Route::middleware(['throttle:server-api', CheckSharedSecretMiddleware::class])
    ->withoutMiddleware(SubstituteBindings::class)
    ->prefix('server/{server}')
    ->group(function () {
        Route::get('config/{type}', [ServerProvisionController::class, 'config'])->name('api.server.config');
        Route::get('scripts/{script}', [ServerProvisionController::class, 'script'])->name('api.server.script');
        Route::post('register', [ServerProvisionController::class, 'register'])->name('api.server.register');
        Route::post('heartbeat', [ServerProvisionController::class, 'heartbeat'])->name('api.server.heartbeat');

        Route::post('stream/play', [StreamController::class, 'play'])->name('api.stream.play');
        Route::post('stream/stop', [StreamController::class, 'stop'])->name('api.stream.stop');
    });

// Control surface (Bitfocus Companion and anything else that can send an HTTP request).
// One key for the installation, the source in the path; see docs/admin/companion.md.
// The limit is per IP, and a control room is one IP with several surfaces on it: three
// stages polling every second is 180 requests a minute before anyone presses anything.
Route::middleware([CheckCompanionTokenMiddleware::class, 'throttle:600,1'])
    ->prefix('companion')
    ->group(function () {
        Route::get('{source:slug}/status', [CompanionController::class, 'status'])->name('api.companion.status');
        Route::post('{source:slug}/start', [CompanionController::class, 'start'])->name('api.companion.start');
        Route::post('{source:slug}/stop', [CompanionController::class, 'stop'])->name('api.companion.stop');
    });

// HLS session tracking endpoints.
//
// The per-request `auth` endpoint is gone: edges verify playback tokens locally in
// njs, so nothing on the media path calls back here. Counting runs on the aggregate
// heartbeat instead.
Route::prefix('hls')->group(function () {
    Route::post('heartbeat', [HlsSessionController::class, 'heartbeat'])->name('api.hls.heartbeat');
});

// SRS callbacks.
//
// `auth` is on_publish and stands on its own: it resolves the source's stream key out of
// the publisher's RTMP query string itself, and it is the one callback whose response SRS
// acts on, so a second gate in front of it could only ever reject a live publisher.
Route::prefix('srs')->group(function () {
    Route::post('auth', [SrsCallbackController::class, 'auth'])->name('api.srs.auth');
});

// The rest are notifications, and every one of them used to be open to anyone who could
// reach the app - on_unpublish takes a source off air on nothing but a slug in the body.
// CheckSrsCallbackMiddleware proves the caller is a server using what SRS already sends,
// so no deployed origin needs reconfiguring. Refusing a notification cannot interrupt a
// stream: the publisher has gone by the time on_unpublish fires, and SRS discards the
// reply.
Route::middleware([CheckSrsCallbackMiddleware::class])->prefix('srs')->group(function () {
    Route::post('play', [SrsCallbackController::class, 'play'])->name('api.srs.play');
    Route::post('stop', [SrsCallbackController::class, 'stop'])->name('api.srs.stop');
    Route::post('unpublish', [SrsCallbackController::class, 'unpublish'])->name('api.srs.unpublish');
    Route::post('error', [SrsCallbackController::class, 'error'])->name('api.srs.error');
    Route::post('on-hls', [SrsCallbackController::class, 'onHls'])->name('api.srs.on-hls');
    Route::post('on-play', [SrsCallbackController::class, 'play'])->name('api.srs.on-play');
    Route::post('on-stop', [SrsCallbackController::class, 'stop'])->name('api.srs.on-stop');
});

// Recording API endpoints for external processing server
Route::middleware([CheckRecordingApiKeyMiddleware::class])->prefix('recording')->group(function () {
    Route::get('shows', [RecordingApiController::class, 'shows'])->name('api.recording.shows');
    Route::post('create', [RecordingApiController::class, 'create'])->name('api.recording.create');
});

// Offline imports: an edit encoded on someone's machine, uploaded into the archive and
// committed as a cut. See App\Services\ArchiveImportService and tools/streaming-archiver.
//
// Its own key, from /manage > Settings > Imports, rather than the deploy-time
// RECORDING_API_KEY above: an import key is handed to a person for as long as they are
// cutting recordings, and taken back by rotating a row.
Route::middleware([CheckImportKeyMiddleware::class])->prefix('recording/imports')->group(function () {
    Route::post('/', [ArchiveImportController::class, 'store'])->name('api.recording.imports.store');
    Route::post('{import}/urls', [ArchiveImportController::class, 'urls'])->name('api.recording.imports.urls');
    Route::post('{import}/commit', [ArchiveImportController::class, 'commit'])->name('api.recording.imports.commit');
});

// Public recording endpoint
Route::get('recording/{slug}', [RecordingApiController::class, 'getBySlug'])->name('api.recording.get');

// The Telegram bot's only inbound endpoint: commands from a linked chat and the buttons
// the bot itself put there. Authenticated by the secret token Telegram echoes back on
// every call, which is set when the webhook is registered; anything else gets a 404.
Route::post('telegram/webhook', TelegramWebhookController::class)
    ->middleware([CheckTelegramWebhookMiddleware::class])
    ->name('api.telegram.webhook');
