<?php

namespace App\Support\Auth;

use App\Models\AuthProvider;
use App\Models\User;
use App\Models\UserIdentity;
use Laravel\Socialite\Contracts\User as RemoteUser;

/**
 * The one place a provider response becomes an account.
 *
 * Four outcomes, in this order: the identity is known, so sign that account in; the
 * person is already signed in, so attach it to them; the address already belongs to
 * somebody here, so refuse; otherwise create the account.
 *
 * The third is the rule that matters. Any case-insensitive match on `users.email`
 * blocks, and nothing is ever merged automatically. `users.email` is not unique, so
 * there may be several matches and nothing to pick between; `email_verified_at` on our
 * side does not discriminate, because every provider sign-in and every admin-created
 * account sets it; and the provider's own verified flag is not uniformly available -
 * Socialite's user contract exposes none, Google puts it in the raw response and
 * GitHub publishes none at all. A rule built on a flag half the drivers lack behaves
 * differently per driver, which is worse than blocking. The cost of blocking is one
 * person doing a manual connect; the cost of not is two strangers sharing an account.
 *
 * A response with no email never collides: there is nothing to match on, so the
 * account is created without an address, which is already the handled case.
 */
final class IdentityLinker
{
    public function resolve(AuthProvider $provider, RemoteUser $remote, ?User $current): LinkResult
    {
        $subject = (string) $remote->getId();

        if ($subject === '') {
            return LinkResult::refused('Your account details could not be read from '.$provider->label.'.');
        }

        $identity = UserIdentity::query()
            ->where('auth_provider_id', $provider->id)
            ->where('subject', $subject)
            ->first();

        if ($identity !== null) {
            if ($current !== null && $current->id !== $identity->user_id) {
                return LinkResult::refused("That {$provider->label} account is connected to a different account here.");
            }

            $this->refresh($identity, $remote);

            return LinkResult::ok($identity->user, $identity);
        }

        if ($current !== null) {
            if ($current->identities()->where('auth_provider_id', $provider->id)->exists()) {
                return LinkResult::refused("This account is already connected to {$provider->label}.");
            }

            return LinkResult::ok($current, $this->attach($provider, $current, $remote));
        }

        $email = $remote->getEmail();

        if (filled($email) && $this->addressIsTaken($email)) {
            return LinkResult::refused(
                "That address already belongs to an account here. Sign in to it, then add {$provider->label} from your settings."
            );
        }

        $user = User::create([
            // The legacy column, still written while it is there: a chat ban parked
            // against a deleted account is claimed off it, and the account export
            // reads it. It is dropped in a later release.
            'sub' => $subject,
            'name' => $remote->getName() ?: $remote->getNickname() ?: $provider->label.' user',
            'email' => $email ?: null,
            'avatar' => $remote->getAvatar(),
            // The provider owns the address, so it arrives confirmed. Only an account
            // this installation holds itself has anything to prove.
            'email_verified_at' => now(),
        ]);

        return LinkResult::ok($user, $this->attach($provider, $user, $remote), created: true);
    }

    /**
     * The provider owns its own row and nothing else. `users.email` is deliberately
     * left alone here: with several providers, rewriting it on every sign-in is two of
     * them fighting over one column.
     */
    private function refresh(UserIdentity $identity, RemoteUser $remote): void
    {
        $identity->forceFill([
            'email' => $remote->getEmail(),
            'name' => $remote->getName() ?: $remote->getNickname(),
            'avatar' => $remote->getAvatar(),
            'last_login_at' => now(),
        ])->save();
    }

    private function attach(AuthProvider $provider, User $user, RemoteUser $remote): UserIdentity
    {
        return UserIdentity::create([
            'user_id' => $user->id,
            'auth_provider_id' => $provider->id,
            'subject' => (string) $remote->getId(),
            'email' => $remote->getEmail(),
            'name' => $remote->getName() ?: $remote->getNickname(),
            'avatar' => $remote->getAvatar(),
            'last_login_at' => now(),
        ]);
    }

    private function addressIsTaken(string $email): bool
    {
        return User::query()
            ->whereNotNull('email')
            ->whereRaw('lower(email) = ?', [mb_strtolower($email)])
            ->exists();
    }
}
