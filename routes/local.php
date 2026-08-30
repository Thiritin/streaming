<?php

use App\Http\Controllers\Local\DebugController;
use App\Http\Controllers\Local\MailPreviewController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

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

    /*
     * The notification emails, in a browser. Rendered from the same MailMessage the
     * mailer would send, so the preview cannot drift from the real thing.
     */
    Route::get('/mail', [MailPreviewController::class, 'index'])->name('mail.index');
    Route::get('/mail/{template}', [MailPreviewController::class, 'show'])->name('mail.show');

    Route::post('/login/{user}', [DebugController::class, 'loginAs'])->name('login');
    Route::post('/persona', [DebugController::class, 'persona'])->name('persona');
    Route::post('/reset', [DebugController::class, 'reset'])->name('reset');
    Route::post('/logout', [DebugController::class, 'logout'])->name('logout');
});

/*
 * Archive segments streamed through the app, on its own origin.
 *
 * Local only, and registered here rather than in web.php so it cannot reach production
 * even by accident. Enabled with ARCHIVE_URL_MODE=proxy.
 *
 * The dev S3 (versitygw) sends no CORS headers and speaks plain HTTP, so hls.js cannot
 * read segments from it: XHR needs Access-Control-Allow-Origin, and a TLS page blocks
 * plain HTTP subresources as mixed content. A presigned URL cannot simply be proxied to
 * fix that, because the SigV4 signature covers the Host header.
 *
 * Production serves segments straight from the bucket instead, which needs a CORS policy
 * on it. See docs/dvr-archive-plan.md.
 */
Route::get('/archive-media/{path}', function (string $path) {
    abort_unless(config('stream.archive_url_mode') === 'proxy', 404);
    abort_unless(str_starts_with($path, 'archive/'), 404);

    $disk = Storage::disk(config('stream.archive_disk'));
    abort_unless($disk->exists($path), 404);

    return $disk->response($path, null, [
        'Content-Type' => str_ends_with($path, '.ts') ? 'video/mp2t' : 'application/octet-stream',
        'Cache-Control' => 'private, max-age=300',
    ]);
})->where('path', '.*')->name('archive.segment');
