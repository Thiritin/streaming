<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Providers\RouteServiceProvider;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Public self-registration, behind its own switch on top of password accounts.
 *
 * Address uniqueness is enforced here rather than by an index: the identity provider
 * rewrites `users.email` from its claim on every sign-in, so a unique column would turn
 * two provider accounts sharing an address into a failed sign-in. Scoped to accounts
 * that hold a password, which is the set this address has to be unique within.
 */
class RegisteredUserController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Auth/Register');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required', 'string', 'email', 'max:255',
                Rule::unique(User::class, 'email')->whereNotNull('password'),
            ],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $user = User::create([
            // No identity provider behind this one.
            'sub' => null,
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
        ]);

        /*
         * No baseline role yet. It is what makes an account an attendee, and a form
         * open to the internet is not evidence that the address belongs to whoever
         * filled it in; App\Listeners\AssignBaselineRole hands it over on Verified.
         */
        event(new Registered($user));

        Auth::login($user);

        $request->session()->regenerate();

        return redirect(RouteServiceProvider::HOME);
    }
}
