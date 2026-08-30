<?php

use App\Http\Controllers\Manage\ArchiveStorageTestController;
use App\Http\Controllers\Manage\AuthProviderController;
use App\Http\Controllers\Manage\CategoryController;
use App\Http\Controllers\Manage\CommentController;
use App\Http\Controllers\Manage\DashboardController;
use App\Http\Controllers\Manage\DisplayScreenController;
use App\Http\Controllers\Manage\EmbedKeyController;
use App\Http\Controllers\Manage\EmoteController;
use App\Http\Controllers\Manage\EventController;
use App\Http\Controllers\Manage\FeedbackController;
use App\Http\Controllers\Manage\PretalxConnectionController;
use App\Http\Controllers\Manage\PretalxImportController;
use App\Http\Controllers\Manage\RecordingController;
use App\Http\Controllers\Manage\RecordingPlanController;
use App\Http\Controllers\Manage\RoleController;
use App\Http\Controllers\Manage\ServerController;
use App\Http\Controllers\Manage\ServerInstallScriptController;
use App\Http\Controllers\Manage\ServerProvisionController;
use App\Http\Controllers\Manage\SettingsController;
use App\Http\Controllers\Manage\ShowController;
use App\Http\Controllers\Manage\ShowPlannerController;
use App\Http\Controllers\Manage\ShowStatisticsController;
use App\Http\Controllers\Manage\SourceController;
use App\Http\Controllers\Manage\SourcePreviewController;
use App\Http\Controllers\Manage\TableColumnController;
use App\Http\Controllers\Manage\TelegramController;
use App\Http\Controllers\Manage\UploadController;
use App\Http\Controllers\Manage\UserController;
use App\Http\Controllers\Manage\UserVerificationController;
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
    Route::post('install-script/rotate', [ServerInstallScriptController::class, 'rotate'])
        ->name('install-script.rotate');
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
Route::middleware('screens.enabled')->group(function () {
    Route::get('embed-keys', [EmbedKeyController::class, 'index'])->name('embed-keys.index');
    Route::get('embed-keys/create', [EmbedKeyController::class, 'create'])->name('embed-keys.create');
    Route::post('embed-keys', [EmbedKeyController::class, 'store'])->name('embed-keys.store');
    Route::post('embed-keys/{embedKey}/sign-out', [EmbedKeyController::class, 'signOut'])->name('embed-keys.sign-out');
    Route::delete('embed-keys/{embedKey}', [EmbedKeyController::class, 'destroy'])->name('embed-keys.destroy');
    Route::post('embed-keys/{embedKey}/direct', [EmbedKeyController::class, 'direct'])->name('embed-keys.direct');
});

/*
 * The screens those keys let in. Bulk routes first, so 'displays/bulk' is not read
 * as a screen id.
 */
Route::middleware('screens.enabled')->group(function () {
    Route::post('displays/bulk/direct', [DisplayScreenController::class, 'bulkDirect'])->name('displays.bulk.direct');
    Route::post('displays/direct-all', [DisplayScreenController::class, 'directAll'])->name('displays.direct-all');
    Route::get('displays', [DisplayScreenController::class, 'index'])->name('displays.index');
    Route::post('displays/{displayScreen}/direct', [DisplayScreenController::class, 'direct'])->name('displays.direct');
    Route::post('displays/{displayScreen}/rename', [DisplayScreenController::class, 'rename'])->name('displays.rename');
    Route::delete('displays/{displayScreen}', [DisplayScreenController::class, 'destroy'])->name('displays.destroy');
});

Route::get('sources', [SourceController::class, 'index'])->name('sources.index');
/*
 * Declared before `sources/{source}` so it is not read as a slug. The player here
 * asks for the playlist with `preview=1`, which HlsController honours for an operator
 * and which keeps the check out of the source's viewer count.
 */
Route::get('sources/preview', SourcePreviewController::class)->name('sources.preview');
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

/*
 * The recording plan's write endpoints. Declared with the other bulk routes so
 * 'shows/recording-plan' is not read as a show id.
 */
Route::post('shows/recording-plan/bulk', [RecordingPlanController::class, 'bulkUpdate'])
    ->name('shows.recording-plan.bulk');
Route::patch('shows/{show}/recording-plan', [RecordingPlanController::class, 'update'])
    ->name('shows.recording-plan');

Route::post('shows/bulk/category', [ShowController::class, 'bulkCategory'])->name('shows.bulk.category');
Route::post('shows/bulk/event', [ShowController::class, 'bulkEvent'])->name('shows.bulk.event');
Route::post('shows/bulk/cancel', [ShowController::class, 'bulkCancel'])->name('shows.bulk.cancel');
Route::post('shows/bulk/archive', [ShowController::class, 'bulkArchive'])->name('shows.bulk.archive');
Route::post('shows/bulk/unarchive', [ShowController::class, 'bulkUnarchive'])->name('shows.bulk.unarchive');
Route::delete('shows/bulk', [ShowController::class, 'bulkDestroy'])->name('shows.bulk.destroy');
Route::post('shows/{show}/go-live', [ShowController::class, 'goLive'])->name('shows.go-live');
Route::post('shows/{show}/end', [ShowController::class, 'endStream'])->name('shows.end');
Route::post('shows/{show}/cancel', [ShowController::class, 'cancel'])->name('shows.cancel');
Route::post('shows/{show}/status', [ShowController::class, 'setStatus'])->name('shows.status');
Route::post('shows/{show}/archive', [ShowController::class, 'archive'])->name('shows.archive');
Route::post('shows/{show}/unarchive', [ShowController::class, 'unarchive'])->name('shows.unarchive');
Route::get('shows/{show}/statistics', ShowStatisticsController::class)->name('shows.statistics');

/*
 * One field of one show, saved from the list while inline editing is switched on. Its own
 * endpoint rather than the form's PUT: that one validates a whole show, and this one is
 * sent a single key.
 */
Route::patch('shows/{show}/inline', [ShowController::class, 'inlineUpdate'])->name('shows.inline');

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
 * Most users arrive through OIDC, and everything the provider owns is read-only.
 * The create route is for the accounts this installation holds itself: a name, an
 * address and a password, which is also all the password routes below touch.
 */
Route::get('users', [UserController::class, 'index'])->name('users.index');
Route::get('users/create', [UserController::class, 'create'])->name('users.create');
Route::post('users', [UserController::class, 'store'])->name('users.store');
Route::get('users/{user}', [UserController::class, 'edit'])->name('users.edit');
Route::put('users/{user}', [UserController::class, 'update'])->name('users.update');
Route::put('users/{user}/password', [UserController::class, 'updatePassword'])->name('users.password.update');
Route::delete('users/{user}/password', [UserController::class, 'destroyPassword'])->name('users.password.destroy');
Route::post('users/{user}/verify', [UserVerificationController::class, 'store'])->name('users.verify');
Route::delete('users/{user}', [UserController::class, 'destroy'])->name('users.destroy');

/*
 * What viewers said under a recording. Bulk route first, so 'comments/bulk' is not
 * read as a comment id.
 */
Route::middleware('comments.enabled')->group(function () {
    Route::delete('comments/bulk', [CommentController::class, 'bulkDestroy'])->name('comments.bulk.destroy');
    Route::post('comments/bulk/approve', [CommentController::class, 'bulkApprove'])->name('comments.bulk.approve');
    Route::get('comments', [CommentController::class, 'index'])->name('comments.index');
    Route::get('comments/{comment}', [CommentController::class, 'show'])->name('comments.show');
    Route::post('comments/{comment}/approve', [CommentController::class, 'approve'])->name('comments.approve');
    Route::post('comments/{comment}/ban', [CommentController::class, 'ban'])->name('comments.ban');
    Route::delete('comments/{comment}', [CommentController::class, 'destroy'])->name('comments.destroy');
});

/*
 * Viewer reports: feedback from the top bar, stream problems from the player. Bulk
 * routes first, so 'feedback/bulk' is not read as a report id.
 */
Route::middleware('feedback.enabled')->group(function () {
    Route::post('feedback/bulk/resolve', [FeedbackController::class, 'bulkResolve'])->name('feedback.bulk.resolve');
    Route::delete('feedback/bulk', [FeedbackController::class, 'bulkDestroy'])->name('feedback.bulk.destroy');
    Route::get('feedback', [FeedbackController::class, 'index'])->name('feedback.index');
    Route::get('feedback/{feedback}', [FeedbackController::class, 'show'])->name('feedback.show');
    Route::post('feedback/{feedback}/status', [FeedbackController::class, 'updateStatus'])->name('feedback.status');
    Route::delete('feedback/{feedback}', [FeedbackController::class, 'destroy'])->name('feedback.destroy');
});

/*
 * The Telegram bot's chats. One bot for the installation lives in Settings; this is
 * which chats it talks to and what each of them may do. Behind the telegram feature
 * switch, so an installation that does not use it has no module and no routes.
 */
Route::middleware('telegram.enabled')->group(function () {
    Route::post('telegram/code', [TelegramController::class, 'code'])->name('telegram.code');
    Route::get('telegram', [TelegramController::class, 'index'])->name('telegram.index');
    Route::post('telegram', [TelegramController::class, 'store'])->name('telegram.store');
    Route::get('telegram/{telegram}', [TelegramController::class, 'edit'])->name('telegram.edit');
    Route::put('telegram/{telegram}', [TelegramController::class, 'update'])->name('telegram.update');
    Route::post('telegram/{telegram}/test', [TelegramController::class, 'test'])->name('telegram.test');
    Route::delete('telegram/{telegram}', [TelegramController::class, 'destroy'])->name('telegram.destroy');
});

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

/*
 * Who is recording what, and what nobody has recorded. Sits before 'recordings/{recording}'
 * so 'plan' is not read as a recording id.
 */
Route::get('recordings/plan', [RecordingPlanController::class, 'index'])->name('recordings.plan');

Route::post('recordings/storage/rescan', [RecordingController::class, 'rescanStorage'])
    ->name('recordings.storage.rescan');
Route::post('recordings/bulk/category', [RecordingController::class, 'bulkCategory'])->name('recordings.bulk.category');
Route::post('recordings/bulk/event', [RecordingController::class, 'bulkEvent'])->name('recordings.bulk.event');
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
 * System settings: identity, login copy, colours, links, the announcement banner.
 * Generated from config/settings.php, so a new knob needs no route change and a new
 * pane needs no route of its own.
 */
/*
 * Categories: what kind of thing a show is. A label, gating nothing.
 *
 * Under settings and declared before the generated panes, or `settings/categories`
 * would be read as a registry group and 404.
 */
Route::get('settings/categories', [CategoryController::class, 'index'])->name('categories.index');
Route::get('settings/categories/create', [CategoryController::class, 'create'])->name('categories.create');
Route::post('settings/categories', [CategoryController::class, 'store'])->name('categories.store');
Route::get('settings/categories/{category}', [CategoryController::class, 'edit'])->name('categories.edit');
Route::put('settings/categories/{category}', [CategoryController::class, 'update'])->name('categories.update');
Route::delete('settings/categories/{category}', [CategoryController::class, 'destroy'])->name('categories.destroy');

/*
 * Events: the runs of the convention and the days they cover. Same placement as
 * categories - a settings area whose contents are rows - and declared before the
 * generated panes for the same reason.
 */
Route::get('settings/events', [EventController::class, 'index'])->name('events.index');
Route::get('settings/events/create', [EventController::class, 'create'])->name('events.create');
Route::post('settings/events', [EventController::class, 'store'])->name('events.store');
Route::get('settings/events/{event}', [EventController::class, 'edit'])->name('events.edit');
Route::put('settings/events/{event}', [EventController::class, 'update'])->name('events.update');
Route::post('settings/events/{event}/match', [EventController::class, 'match'])->name('events.match');
Route::delete('settings/events/{event}', [EventController::class, 'destroy'])->name('events.destroy');

/*
 * Sign-in providers: the ways in that are not a password. Rows, like events and
 * categories, and declared before the generated panes for the same reason.
 */
Route::get('settings/providers', [AuthProviderController::class, 'index'])->name('providers.index');
Route::get('settings/providers/create', [AuthProviderController::class, 'create'])->name('providers.create');
Route::post('settings/providers', [AuthProviderController::class, 'store'])->name('providers.store');
Route::get('settings/providers/{provider}', [AuthProviderController::class, 'edit'])->name('providers.edit');
Route::put('settings/providers/{provider}', [AuthProviderController::class, 'update'])->name('providers.update');
Route::delete('settings/providers/{provider}', [AuthProviderController::class, 'destroy'])->name('providers.destroy');
// A real round trip to the real provider, which writes nothing. See the controller.
Route::get('settings/providers/{provider}/test', [AuthProviderController::class, 'test'])->name('providers.test');

Route::post('settings/pretalx/test', PretalxConnectionController::class)->name('settings.pretalx.test');

// A real round trip to the bucket, which writes and then removes one small object.
// Throttled because it reaches a third party on request.
Route::post('settings/storage/test', ArchiveStorageTestController::class)
    ->middleware('throttle:10,1')
    ->name('settings.storage.test');
Route::get('settings', [SettingsController::class, 'edit'])->name('settings');
// One pane per registry group; the bare /manage/settings above is the first of them.
Route::get('settings/{group}', [SettingsController::class, 'edit'])->name('settings.group');
Route::put('settings/{group}', [SettingsController::class, 'update'])->name('settings.update');
Route::post('settings/reset', [SettingsController::class, 'reset'])->name('settings.reset');
