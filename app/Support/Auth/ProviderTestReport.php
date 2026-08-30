<?php

namespace App\Support\Auth;

use App\Models\AuthProvider;
use App\Models\Role;
use App\Models\User;
use Laravel\Socialite\Contracts\User as RemoteUser;

/**
 * What one test round trip found, shown once to whoever ran it.
 *
 * Nothing here is written: no account, no identity, no role, and the roles it names
 * are what the mapping *would* grant, worked out from the same code a real sign-in
 * uses so the two cannot answer differently.
 *
 * The collision rule is reported and not applied. Hitting a collision is an ordinary
 * outcome of testing with your own address, and a test that acted on it would be the
 * one thing a test must never be.
 */
final class ProviderTestReport
{
    /**
     * @param  array<string, mixed>  $claims
     * @return array<string, mixed>
     */
    public static function of(AuthProvider $provider, RemoteUser $remote, array $claims): array
    {
        $email = $remote->getEmail();
        $roleIds = ProviderRoles::grantedBy($provider, $claims);

        return self::base($provider) + [
            'ok' => true,
            'subject' => (string) $remote->getId(),
            'name' => $remote->getName() ?: $remote->getNickname(),
            'email' => $email ?: null,
            'claims' => self::claims($claims),
            'roles' => Role::whereIn('id', $roleIds)->ordered()->pluck('name')->all(),
            'notes' => self::notes($provider, $remote, $email),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function failure(AuthProvider $provider, string $reason): array
    {
        return self::base($provider) + ['ok' => false, 'reason' => $reason];
    }

    /**
     * @return array<string, mixed>
     */
    private static function base(AuthProvider $provider): array
    {
        return [
            'provider' => $provider->label,
            'callback' => $provider->redirectUrl(),
            'ranAt' => now()->toIso8601String(),
        ];
    }

    /**
     * What the provider actually released, which is the other half of what an operator
     * came here to see. Scalars and lists only: a claim that is a nested object is
     * reported as present rather than dumped.
     *
     * @param  array<string, mixed>  $claims
     * @return array<int, array{name: string, value: string}>
     */
    private static function claims(array $claims): array
    {
        $rows = [];

        foreach ($claims as $name => $value) {
            if (in_array($name, ['sub', 'name', 'email'], true)) {
                continue;
            }

            $rows[] = ['name' => (string) $name, 'value' => self::readable($value)];
        }

        usort($rows, fn ($a, $b) => $a['name'] <=> $b['name']);

        return $rows;
    }

    private static function readable(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_scalar($value) || $value === null) {
            return (string) ($value ?? '');
        }

        if (is_array($value) && $value === array_filter($value, 'is_scalar')) {
            return implode(', ', array_map('strval', $value));
        }

        return '(structured)';
    }

    /**
     * The things that are not failures but that an operator has to know before the
     * first real person arrives.
     *
     * @return array<int, string>
     */
    private static function notes(AuthProvider $provider, RemoteUser $remote, ?string $email): array
    {
        $notes = [];

        if (blank($email)) {
            $notes[] = 'This provider released no email address. Accounts made through it cannot be sent mail.';
        } elseif (self::addressIsTaken($email)) {
            $notes[] = 'That address already belongs to an account here, so this sign-in would be blocked. '
                .'The person signs in to that account and adds '.$provider->label.' from their settings.';
        }

        if (blank($remote->getId())) {
            $notes[] = 'This provider released no subject, which is what an account is recognised by.';
        }

        if (($provider->role_map ?? []) === [] && ! $provider->grants_baseline) {
            $notes[] = 'This provider grants no roles.';
        }

        return $notes;
    }

    private static function addressIsTaken(string $email): bool
    {
        return User::query()
            ->whereNotNull('email')
            ->whereRaw('lower(email) = ?', [mb_strtolower($email)])
            ->exists();
    }
}
