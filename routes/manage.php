<?php

use App\Http\Controllers\Manage\DashboardController;
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
});

Route::post('servers/provision', [ServerProvisionController::class, 'store'])->name('servers.provision');

/*
 * Streaming: sources first, then the shows that run on them.
 */
Route::post('sources/bulk/status', [SourceController::class, 'bulkUpdateStatus'])->name('sources.bulk.status');
Route::delete('sources/bulk', [SourceController::class, 'bulkDestroy'])->name('sources.bulk.destroy');
Route::post('sources/{source}/status', [SourceController::class, 'updateStatus'])->name('sources.status');
Route::post('sources/{source}/stream-key', [SourceController::class, 'regenerateStreamKey'])->name('sources.stream-key');

Route::get('sources', [SourceController::class, 'index'])->name('sources.index');
Route::get('sources/create', [SourceController::class, 'create'])->name('sources.create');
Route::post('sources', [SourceController::class, 'store'])->name('sources.store');
Route::get('sources/{source}', [SourceController::class, 'edit'])->name('sources.edit');
Route::put('sources/{source}', [SourceController::class, 'update'])->name('sources.update');
Route::delete('sources/{source}', [SourceController::class, 'destroy'])->name('sources.destroy');

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
Route::get('servers/{server}', [ServerController::class, 'edit'])->name('servers.edit');
Route::put('servers/{server}', [ServerController::class, 'update'])->name('servers.update');
Route::delete('servers/{server}', [ServerController::class, 'destroy'])->name('servers.destroy');

/*
 * System settings: identity, login copy, colours, links. Generated from
 * config/settings.php, so a new knob needs no route change.
 */
Route::get('settings', [SettingsController::class, 'edit'])->name('settings');
Route::put('settings', [SettingsController::class, 'update'])->name('settings.update');
Route::post('settings/reset', [SettingsController::class, 'reset'])->name('settings.reset');
