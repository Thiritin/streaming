<?php

namespace App\Http\Controllers;

use App\Support\AccountArchive;
use App\Support\BanCarryOver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * A viewer's own account: taking a copy of it, and closing it.
 *
 * Deleting is a hard delete. Every foreign key into `users` either cascades or goes
 * null, so the row going takes the chat backlog, the comments, the hearts, the watch
 * progress and the follows with it, and leaves the moderation log with a gap where the
 * name was. Nothing is anonymised and kept: a comment left standing under a deleted
 * account is still the thing the account asked to be rid of.
 *
 * The one thing that outlives it is a sanction, parked against the identity by
 * App\Support\BanCarryOver so that deleting and signing in again is not how a ban is
 * lifted.
 */
class AccountController extends Controller
{
    /**
     * The whole account as a zip: the data as JSON and as a CSV per kind, plus
     * whatever the account uploaded.
     *
     * A download rather than a mailed archive: it is assembled from rows this request
     * can already read, so there is nothing to queue and nothing to leave lying in a
     * bucket behind a link.
     */
    public function export(Request $request): BinaryFileResponse
    {
        $user = $request->user();

        return response()
            ->download(AccountArchive::build($user), AccountArchive::filename($user), [
                'Content-Type' => 'application/zip',
            ])
            ->deleteFileAfterSend();
    }

    public function destroy(Request $request): Response
    {
        $user = $request->user();

        $request->validate(['confirmation' => ['required', 'string']]);

        // Typed rather than a password, because an account the identity provider owns
        // has none to ask for.
        if (trim($request->input('confirmation')) !== $user->name) {
            throw ValidationException::withMessages([
                'confirmation' => 'That does not match your account name.',
            ]);
        }

        BanCarryOver::hold($user);

        $local = $user->isLocal();

        Auth::guard('web')->logout();

        $user->delete();

        // An account the provider owns leaves through the front channel, or its next
        // visit is signed straight back in and the account is remade without anyone
        // being asked. That controller ends the session itself.
        //
        // Inertia::location rather than a redirect, because the front channel ends at
        // the provider: an ordinary redirect is followed by the XHR this came in on,
        // which then has the provider's HTML and no Inertia response.
        if (! $local) {
            return Inertia::location(route('auth.frontchannel-logout'));
        }

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Inertia::location('/');
    }
}
