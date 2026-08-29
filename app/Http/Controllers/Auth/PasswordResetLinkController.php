<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Asking for a reset link. The broker is the stock one configured in
 * config/auth.php against the password_reset_tokens table.
 */
class PasswordResetLinkController extends Controller
{
    public function create(Request $request): Response
    {
        return Inertia::render('Auth/ForgotPassword', [
            'status' => $request->session()->get('status'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        /*
         * The same scope the sign-in attempt uses: an account that holds no password
         * of its own is not one a link may set one on. Anything else would hand an
         * account the identity provider owns a second way in that nobody asked for.
         */
        $status = Password::sendResetLink([
            'email' => $request->input('email'),
            fn ($query) => $query->whereNotNull('password'),
        ]);

        /*
         * An address nobody holds answers the same as one that does. On an
         * installation whose addresses come from an identity provider, telling the two
         * apart is telling a stranger which of them has an account here.
         */
        if ($status === Password::RESET_THROTTLED) {
            throw ValidationException::withMessages([
                'email' => [trans($status)],
            ]);
        }

        return back()->with('status', trans(Password::RESET_LINK_SENT));
    }
}
