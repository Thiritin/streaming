<?php

use App\Http\Controllers\AnnouncementController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Auth\EmailVerificationPromptController;
use App\Http\Controllers\Auth\FrontChannelLogoutController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\OidcClientController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Auth\VerifyEmailController;
use App\Http\Controllers\BoopController;
use App\Http\Controllers\Chat\ChatUserController;
use App\Http\Controllers\Chat\ModerationController;
use App\Http\Controllers\DisplayController;
use App\Http\Controllers\EmoteController;
use App\Http\Controllers\FeedbackController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\RecordingCommentController;
use App\Http\Controllers\RecordingController;
use App\Http\Controllers\RecordingPlaylistController;
use App\Http\Controllers\RecordingProgressController;
use App\Http\Controllers\ScheduleController;
use App\Http\Controllers\StreamController;
use App\Http\Controllers\UserSettingsController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

// up route
Route::get('/up', function () {
    return response('OK', 200);
});

/*
 * Signing in. Three modes, switched independently in /manage > Settings > Sign-in
 * and read through App\Support\AuthModes: the identity provider, password accounts
 * this installation holds, and public registration on top of those.
 *
 * The sign-in screen itself carries no switch - it renders whatever is on, down to
 * nothing - while every form behind it does, and a mode that is off answers 404 the
 * same way a switched-off feature does.
 */
Route::middleware('guest')->group(function () {
    Route::get('/auth/login', [OidcClientController::class, 'login'])->name('auth.login');
    Route::get('/auth/callback', [
        OidcClientController::class,
        'callback',
    ])->name('auth.callback');

    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');

    Route::middleware('auth.local')->group(function () {
        Route::post('/login', [AuthenticatedSessionController::class, 'store'])
            ->middleware('throttle:auth')
            ->name('login.store');

        Route::get('/forgot-password', [PasswordResetLinkController::class, 'create'])
            ->name('password.request');
        Route::post('/forgot-password', [PasswordResetLinkController::class, 'store'])
            ->middleware('throttle:auth')
            ->name('password.email');
        Route::get('/reset-password/{token}', [NewPasswordController::class, 'create'])
            ->name('password.reset');
        Route::post('/reset-password', [NewPasswordController::class, 'store'])
            ->middleware('throttle:auth')
            ->name('password.store');

        Route::middleware('auth.register')->group(function () {
            Route::get('/register', [RegisteredUserController::class, 'create'])->name('register');
            Route::post('/register', [RegisteredUserController::class, 'store'])
                ->middleware('throttle:auth')
                ->name('register.store');
        });
    });
});

/*
 * Confirming an address. Deliberately outside the sign-in mode switches: a link
 * already in somebody's inbox has to keep working whatever is turned off after it
 * was sent. Nothing is gated on being verified - what it decides is whether an
 * account that registered itself gets the baseline role; see App\Listeners\AssignBaselineRole.
 */
Route::middleware('auth:web')->group(function () {
    Route::get('/verify-email', EmailVerificationPromptController::class)->name('verification.notice');

    Route::get('/verify-email/{id}/{hash}', VerifyEmailController::class)
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');

    Route::post('/email/verification-notification', [EmailVerificationNotificationController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('verification.send');
});

/*
 * Signing out. A local session ends here; an account the identity provider owns
 * leaves through the front channel below, so the provider hears about it too.
 */
Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])
    ->middleware('auth:web')
    ->name('logout');

Route::get('/auth/frontchannel-logout', FrontChannelLogoutController::class)->name('auth.frontchannel-logout');

/*
 * Unattended displays and external players. Deliberately outside the auth group:
 * the display key is the credential, and a screen in a hallway has no one to log
 * it in. See App\Http\Controllers\DisplayController.
 *
 * The entry point sits at the root, not under /display, because it gets typed by
 * hand into screens with no keyboard: `example.org/d/H7K2-M9FQ` is about as short
 * as an addressable secret gets, and bare `example.org/d` is shorter still for
 * anyone who would rather type the code into a box than into an address bar. The
 * throttle is load-bearing - it is what keeps an 8-character code out of reach of
 * a guesser.
 */
Route::middleware('screens.enabled')->group(function () {
    Route::get('/d', [DisplayController::class, 'prompt'])->name('display.prompt');

    Route::post('/d', [DisplayController::class, 'redeem'])
        ->middleware('throttle:10,1')
        ->name('display.redeem');

    Route::get('/d/{key}', [DisplayController::class, 'enter'])
        ->middleware('throttle:10,1')
        ->name('display.enter');

    Route::prefix('display')->name('display.')->group(function () {
        Route::get('/', [DisplayController::class, 'hub'])->name('hub');
        Route::get('/play', [DisplayController::class, 'play'])->name('play');
        Route::get('/state', [DisplayController::class, 'state'])->name('state');
        Route::post('/leave', [DisplayController::class, 'leave'])->name('leave');
    });
});

/*
 * Watching and browsing. `auth.optional` is the plain auth middleware when
 * AUTH_REQUIRED=true, and a no-op when it is false, in which case guests reach
 * these pages and only see unrestricted shows and recordings.
 */
Route::middleware(['auth.optional:web'])->group(function () {
    Route::get('/', [StreamController::class, 'index'])->name('shows.grid');
    Route::get('/shows', [StreamController::class, 'index'])->name('shows.index');
    Route::get('/show/{show:slug}', [StreamController::class, 'show'])->name('show.view');
    Route::get('/show/{show:slug}/external', [StreamController::class, 'external'])->name('show.external');

    /*
     * Boops. A paw anyone can mash, live-counted for the whole room. Guests
     * included on purpose: nothing is attributed, so there is nothing to sign in
     * for. The throttle plus the 50-per-request cap in BoopController is what
     * bounds a counter with no other limit on it.
     */
    Route::post('/show/{show:slug}/boop', [BoopController::class, 'store'])
        ->middleware('throttle:120,1')
        ->name('show.boop');

    /*
     * Feedback from the top bar, and stream problem reports from the player. Guests
     * included: the viewer whose stream is broken is the least likely to be signed
     * in, and the throttle is what bounds an unauthenticated write.
     */
    Route::post('/feedback', [FeedbackController::class, 'store'])
        ->middleware(['feedback.enabled', 'throttle:10,1'])
        ->name('feedback.store');

    // Programme guide
    Route::get('/schedule', [ScheduleController::class, 'index'])->name('schedule.index');

    // The announcement in full, behind the banner's "read more".
    Route::get('/announcement', [AnnouncementController::class, 'show'])->name('announcement');

    // Archive (formerly "recordings"; route names kept so existing links still resolve)
    Route::get('/archive', [RecordingController::class, 'index'])->name('recordings.index');
    /*
     * Search suggestions for the archive's own search box. Not an Inertia page: it
     * answers while the viewer is still typing.
     */
    Route::get('/archive/suggest', [RecordingController::class, 'suggest'])
        ->middleware('throttle:60,1')
        ->name('recordings.suggest');
    // Declared before /archive/{recording} so the year collection wins the match.
    // The index filters by year in place now; this stays for links already handed out.
    Route::get('/archive/year/{year}', [RecordingController::class, 'year'])
        ->whereNumber('year')
        ->name('recordings.year');
    /*
     * Recording playlists, rendered per request rather than stored.
     *
     * Declared before /archive/{recording} so the .m3u8 paths are not swallowed by the
     * show route. Segments are handed out as presigned URLs so the archive bucket stays
     * private; that makes this the only place `required_roles` can be enforced, and it
     * is why the playlists cannot simply be static objects in S3.
     */
    Route::get('/archive/{slug}/master.m3u8', [RecordingPlaylistController::class, 'master'])
        ->name('recordings.playlist.master');
    Route::get('/archive/{slug}/{rendition}.m3u8', [RecordingPlaylistController::class, 'media'])
        ->name('recordings.playlist.media');

    Route::get('/archive/{recording}', [RecordingController::class, 'show'])->name('recordings.show');

    /*
     * Where a signed-in viewer got to. Posted by the player every few seconds and
     * on the way out; guests keep no progress, so the route is behind auth.
     */
    Route::put('/archive/{recording}/progress', [RecordingProgressController::class, 'update'])
        ->middleware('auth:web')
        ->name('recordings.progress');
    /*
     * Comments under a recording, and taking one down again. Signed-in only:
     * a comment is attributed, so there is nothing to post as a guest. The
     * throttle is what bounds a run of them; the section itself is one Inertia
     * prop on the watch page, so both of these answer a redirect back.
     */
    Route::post('/archive/{recording}/comments', [RecordingCommentController::class, 'store'])
        ->middleware(['auth:web', 'comments.enabled', 'throttle:20,1'])
        ->name('recordings.comments.store');
    Route::patch('/archive/{recording}/comments/{comment}', [RecordingCommentController::class, 'update'])
        ->middleware(['auth:web', 'comments.enabled', 'throttle:30,1'])
        ->name('recordings.comments.update');
    Route::delete('/archive/{recording}/comments/{comment}', [RecordingCommentController::class, 'destroy'])
        ->middleware(['auth:web', 'comments.enabled'])
        ->name('recordings.comments.destroy');
    Route::post('/archive/{recording}/comments/{comment}/heart', [RecordingCommentController::class, 'heart'])
        ->middleware(['auth:web', 'comments.enabled', 'throttle:120,1'])
        ->name('recordings.comments.heart');
    /*
     * Reporting takes a comment down on the spot and asks a moderator afterwards;
     * approving puts it back. Both signed-in, the first throttled because it is a
     * write that hides somebody else's words.
     */
    Route::post('/archive/{recording}/comments/{comment}/report', [RecordingCommentController::class, 'report'])
        ->middleware(['auth:web', 'comments.enabled', 'throttle:20,1'])
        ->name('recordings.comments.report');
    Route::post('/archive/{recording}/comments/{comment}/approve', [RecordingCommentController::class, 'approve'])
        ->middleware(['auth:web', 'comments.enabled'])
        ->name('recordings.comments.approve');
    /*
     * Marking skip points from the watch page. Behind the recording policy, so it
     * is the same `stream.manage` the form in /manage sits behind.
     */
    Route::patch('/archive/{recording}/skips', [RecordingController::class, 'updateSkips'])
        ->middleware('auth:web')
        ->name('recordings.skips');

    Route::redirect('/recordings', '/archive');
    Route::get('/recordings/{recording}', fn ($recording) => redirect("/archive/{$recording}"));
});

/*
 * A viewer's own settings: which of the installation's features they want. Not
 * behind any feature middleware, because this is the page that turns them back
 * on.
 */
Route::middleware('auth:web')->group(function () {
    Route::get('/settings', [UserSettingsController::class, 'edit'])->name('settings.edit');
    Route::patch('/settings', [UserSettingsController::class, 'update'])->name('settings.update');
});

/*
 * Chat, and the emote library that only exists for it. Always sign-in only,
 * even when login is otherwise optional, because every message is attributed,
 * rate limited and moderated per user. `chat.enabled` answers 404 for the whole
 * group when chat is switched off in /manage > Settings.
 */
Route::middleware(['auth:web', 'chat.enabled'])->group(function () {
    Route::get('/show/{show:slug}/chat', [StreamController::class, 'chat'])->name('show.chat');
    Route::post('/message/send', [MessageController::class, 'send'])->name('message.send');
    Route::get('/messages/older', [MessageController::class, 'loadOlder'])->name('messages.older');
    Route::delete('/messages/{message}', [MessageController::class, 'destroy'])->name('messages.destroy');

    // Chat moderation (mod menu + per-message quick actions)
    Route::prefix('chat')->name('chat.')->group(function () {
        Route::get('/users/{user}', [ChatUserController::class, 'show'])->name('users.show');

        Route::prefix('moderation')->name('moderation.')->group(function () {
            Route::get('/', [ModerationController::class, 'index'])->name('index');
            Route::post('/timeout', [ModerationController::class, 'timeout'])->name('timeout');
            Route::post('/untimeout', [ModerationController::class, 'untimeout'])->name('untimeout');
            Route::post('/ban', [ModerationController::class, 'ban'])->name('ban');
            Route::post('/unban', [ModerationController::class, 'unban'])->name('unban');
            Route::post('/purge', [ModerationController::class, 'purge'])->name('purge');
            Route::post('/clear', [ModerationController::class, 'clear'])->name('clear');
            Route::post('/announce', [ModerationController::class, 'announce'])->name('announce');
            Route::post('/settings', [ModerationController::class, 'updateSettings'])->name('settings');
        });
    });

    // Emote routes. Switchable on their own, so chat can stay up without them.
    Route::middleware('emotes.enabled')->group(function () {
        Route::get('/emotes', [EmoteController::class, 'index'])->name('emotes.index');
        Route::post('/emotes', [EmoteController::class, 'store'])->name('emotes.store');
        Route::post('/emotes/{emote}/favorite', [EmoteController::class, 'toggleFavorite'])->name('emotes.favorite');
        Route::delete('/emotes/{emote}', [EmoteController::class, 'destroy'])->name('emotes.destroy');
    });
});

/*
 * The Filament panel used to live at /admin. Bookmarks and muscle memory outlive
 * the panel, so the prefix is kept as a permanent redirect into /manage.
 */
Route::redirect('/admin', '/manage', 301);
Route::redirect('/admin/{path}', '/manage', 301)->where('path', '.*');

Broadcast::routes();
