<?php

use App\Http\Controllers\Manage\DashboardController;
use App\Http\Controllers\Manage\DisplayScreenController;
use App\Http\Controllers\Manage\EmbedKeyController;
use App\Http\Controllers\Manage\EmoteController;
use App\Http\Controllers\Manage\PretalxConnectionController;
use App\Http\Controllers\Manage\PretalxImportController;
use App\Http\Controllers\Manage\RecordingController;
use App\Http\Controllers\Manage\RoleController;
use App\Http\Controllers\Manage\ServerController;
use App\Http\Controllers\Manage\ServerInstallScriptController;
use App\Http\Controllers\Manage\ServerProvisionController;
use App\Http\Controllers\Manage\SettingsController;
use App\Http\Controllers\Manage\ShowController;
use App\Http\Controllers\Manage\ShowPlannerController;
use App\Http\Controllers\Manage\ShowStatisticsController;
use App\Http\Controllers\Manage\SourceController;
use App\Http\Controllers\Manage\TableColumnController;
use App\Http\Controllers\Manage\UploadController;
use App\Http\Controllers\Manage\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Manage routes
|--------------------------------------------------------------------------
|
| The Inertia admin panel. Runs in parallel with the Filament panel at /admin
| until the parity suite in tests/Feature/Manage is green; see
| docs/admin/rebuild-plan.md.
|
| Guests are pushed into the existing OIDC flow by `auth:web`. There is no
| separate login screen here, unlike /admin/login.
|
*/

/*
 * The dashboard: capacity, server health, alerts, live viewers and the next few hours of
 * programme on one screen, for the maintainer and the producer.
 */
Route::get('/', DashboardController::class)->name('home');

Route::post('/uploads', [UploadController::class, 'store'])->name('uploads.store');

Route::post('/tables/{table}/columns', [TableColumnController::class, 'update'])
    ->name('tables.columns');

/*
 * Infrastructure. The install-script routes sit before the resource routes so
 * `servers/{server}/install-script` is not swallowed by the edit route.
 */
Route::prefix('servers/{server}')->name('servers.')->group(function () {
    Route::get('install-script', [ServerInstallScriptController::class, 'show'])->name('install-script');
    Route::get('install-script/download', [ServerInstallScriptController::class, 'download'])
        ->name('install-script.download');
    Route::post('install-script/regenerate', [ServerInstallScriptController::class, 'regenerate'])
        ->name('install-script.regenerate');
    Route::post('deprovision', [ServerController::class, 'deprovision'])->name('deprovision');
    Route::post('force-deprovision', [ServerController::class, 'forceDeprovision'])
        ->name('force-deprovision');
});

Route::post('servers/provision', [ServerProvisionController::class, 'store'])->name('servers.provision');

/*
 * Streaming: sources first, then the shows that run on them.
 */
Route::post('sources/bulk/status', [SourceController::class, 'bulkUpdateStatus'])->name('sources.bulk.status');
Route::delete('sources/bulk', [SourceController::class, 'bulkDestroy'])->name('sources.bulk.destroy');
Route::post('sources/{source}/status', [SourceController::class, 'updateStatus'])->name('sources.status');
Route::post('sources/{source}/stream-key', [SourceController::class, 'regenerateStreamKey'])->name('sources.stream-key');

/*
 * Display keys. No edit route on purpose: a key is a name and a secret, and
 * revoking is deleting the row.
 */
Route::get('embed-keys', [EmbedKeyController::class, 'index'])->name('embed-keys.index');
Route::get('embed-keys/create', [EmbedKeyController::class, 'create'])->name('embed-keys.create');
Route::post('embed-keys', [EmbedKeyController::class, 'store'])->name('embed-keys.store');
Route::post('embed-keys/{embedKey}/sign-out', [EmbedKeyController::class, 'signOut'])->name('embed-keys.sign-out');
Route::delete('embed-keys/{embedKey}', [EmbedKeyController::class, 'destroy'])->name('embed-keys.destroy');
Route::post('embed-keys/{embedKey}/direct', [EmbedKeyController::class, 'direct'])->name('embed-keys.direct');

/*
 * The screens those keys let in. Bulk routes first, so 'displays/bulk' is not read
 * as a screen id.
 */
Route::post('displays/bulk/direct', [DisplayScreenController::class, 'bulkDirect'])->name('displays.bulk.direct');
Route::post('displays/direct-all', [DisplayScreenController::class, 'directAll'])->name('displays.direct-all');
Route::get('displays', [DisplayScreenController::class, 'index'])->name('displays.index');
Route::post('displays/{displayScreen}/direct', [DisplayScreenController::class, 'direct'])->name('displays.direct');
Route::post('displays/{displayScreen}/rename', [DisplayScreenController::class, 'rename'])->name('displays.rename');
Route::delete('displays/{displayScreen}', [DisplayScreenController::class, 'destroy'])->name('displays.destroy');

Route::get('sources', [SourceController::class, 'index'])->name('sources.index');
Route::get('sources/create', [SourceController::class, 'create'])->name('sources.create');
Route::post('sources', [SourceController::class, 'store'])->name('sources.store');
Route::get('sources/{source}', [SourceController::class, 'edit'])->name('sources.edit');
Route::put('sources/{source}', [SourceController::class, 'update'])->name('sources.update');
Route::delete('sources/{source}', [SourceController::class, 'destroy'])->name('sources.destroy');

/*
 * Programme import. Sits before the `shows/{show}` routes so `shows/import` is not read
 * as a show id.
 */
Route::get('shows/import', [PretalxImportController::class, 'index'])->name('shows.import');
Route::post('shows/import', [PretalxImportController::class, 'store'])->name('shows.import.store');
Route::post('shows/import/refresh', [PretalxImportController::class, 'refresh'])->name('shows.import.refresh');

Route::get('shows/planner', [ShowPlannerController::class, 'index'])->name('shows.planner');
Route::post('shows/planner', [ShowPlannerController::class, 'store'])->name('shows.planner.store');
Route::patch('shows/{show}/schedule', [ShowPlannerController::class, 'reschedule'])->name('shows.reschedule');

Route::post('shows/bulk/cancel', [ShowController::class, 'bulkCancel'])->name('shows.bulk.cancel');
Route::delete('shows/bulk', [ShowController::class, 'bulkDestroy'])->name('shows.bulk.destroy');
Route::post('shows/{show}/go-live', [ShowController::class, 'goLive'])->name('shows.go-live');
Route::post('shows/{show}/end', [ShowController::class, 'endStream'])->name('shows.end');
Route::post('shows/{show}/cancel', [ShowController::class, 'cancel'])->name('shows.cancel');
Route::get('shows/{show}/statistics', ShowStatisticsController::class)->name('shows.statistics');

Route::get('shows', [ShowController::class, 'index'])->name('shows.index');
Route::get('shows/create', [ShowController::class, 'create'])->name('shows.create');
Route::post('shows', [ShowController::class, 'store'])->name('shows.store');
Route::get('shows/{show}', [ShowController::class, 'edit'])->name('shows.edit');
Route::put('shows/{show}', [ShowController::class, 'update'])->name('shows.update');
Route::delete('shows/{show}', [ShowController::class, 'destroy'])->name('shows.destroy');

Route::get('servers', [ServerController::class, 'index'])->name('servers.index');
Route::get('servers/create', [ServerController::class, 'create'])->name('servers.create');
Route::post('servers', [ServerController::class, 'store'])->name('servers.store');
Route::get('servers/{server}', [ServerController::class, 'show'])->name('servers.show');
Route::get('servers/{server}/edit', [ServerController::class, 'edit'])->name('servers.edit');
Route::put('servers/{server}', [ServerController::class, 'update'])->name('servers.update');
Route::delete('servers/{server}', [ServerController::class, 'destroy'])->name('servers.destroy');

/*
 * Users arrive through OIDC, so there is no create route: an operator can change
 * the edge assignment and the attached roles, nothing else.
 */
Route::get('users', [UserController::class, 'index'])->name('users.index');
Route::get('users/{user}', [UserController::class, 'edit'])->name('users.edit');
Route::put('users/{user}', [UserController::class, 'update'])->name('users.update');
Route::delete('users/{user}', [UserController::class, 'destroy'])->name('users.destroy');

Route::post('roles/seed', [RoleController::class, 'seedDefaults'])->name('roles.seed');
Route::get('roles', [RoleController::class, 'index'])->name('roles.index');
Route::get('roles/create', [RoleController::class, 'create'])->name('roles.create');
Route::post('roles', [RoleController::class, 'store'])->name('roles.store');
Route::get('roles/{role}', [RoleController::class, 'edit'])->name('roles.edit');
Route::put('roles/{role}', [RoleController::class, 'update'])->name('roles.update');
Route::delete('roles/{role}', [RoleController::class, 'destroy'])->name('roles.destroy');

Route::post('emotes/bulk/approve', [EmoteController::class, 'bulkApprove'])->name('emotes.bulk.approve');
Route::delete('emotes/bulk', [EmoteController::class, 'bulkDestroy'])->name('emotes.bulk.destroy');
Route::post('emotes/{emote}/approve', [EmoteController::class, 'approve'])->name('emotes.approve');
Route::get('emotes', [EmoteController::class, 'index'])->name('emotes.index');
Route::get('emotes/create', [EmoteController::class, 'create'])->name('emotes.create');
Route::post('emotes', [EmoteController::class, 'store'])->name('emotes.store');
Route::get('emotes/{emote}', [EmoteController::class, 'edit'])->name('emotes.edit');
Route::put('emotes/{emote}', [EmoteController::class, 'update'])->name('emotes.update');
Route::delete('emotes/{emote}', [EmoteController::class, 'destroy'])->name('emotes.destroy');

Route::post('recordings/storage/rescan', [RecordingController::class, 'rescanStorage'])
    ->name('recordings.storage.rescan');
Route::post('recordings/bulk/thumbnail', [RecordingController::class, 'bulkRegenerateThumbnails'])
    ->name('recordings.bulk.thumbnail');
Route::delete('recordings/bulk', [RecordingController::class, 'bulkDestroy'])->name('recordings.bulk.destroy');
Route::post('recordings/{recording}/thumbnail', [RecordingController::class, 'regenerateThumbnail'])
    ->name('recordings.thumbnail');

/*
 * Cutting. A recording is a time range over its source's continuous archive, so these
 * never wait for a show to end: the main source stays online for the whole event.
 * Rebuild regenerates the playlist from the current markers, which is also how a cut
 * made at the live edge picks up the segments the uploader had not caught up with yet.
 */
Route::post('shows/{show}/recording', [RecordingController::class, 'storeFromShow'])
    ->name('shows.recording.store');
Route::post('recordings/{recording}/rebuild', [RecordingController::class, 'rebuild'])
    ->name('recordings.rebuild');
Route::get('recordings/{recording}/preview.m3u8', [RecordingController::class, 'preview'])
    ->name('recordings.preview');
Route::get('recordings', [RecordingController::class, 'index'])->name('recordings.index');
Route::get('recordings/create', [RecordingController::class, 'create'])->name('recordings.create');
Route::post('recordings', [RecordingController::class, 'store'])->name('recordings.store');
Route::get('recordings/{recording}', [RecordingController::class, 'edit'])->name('recordings.edit');
Route::put('recordings/{recording}', [RecordingController::class, 'update'])->name('recordings.update');
Route::delete('recordings/{recording}', [RecordingController::class, 'destroy'])->name('recordings.destroy');

/*
 * System settings: identity, login copy, colours, links. Generated from
 * config/settings.php, so a new knob needs no route change.
 */
Route::post('settings/pretalx/test', PretalxConnectionController::class)->name('settings.pretalx.test');
Route::get('settings', [SettingsController::class, 'edit'])->name('settings');
Route::put('settings', [SettingsController::class, 'update'])->name('settings.update');
Route::post('settings/reset', [SettingsController::class, 'reset'])->name('settings.reset');
