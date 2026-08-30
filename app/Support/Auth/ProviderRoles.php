<?php

namespace App\Support\Auth;

use App\Models\AuthProvider;
use App\Models\Role;
use App\Models\User;
use App\Models\UserIdentity;

/**
 * What each provider grants, and how several of them add up on one account.
 *
 * The mapping is per provider and points at a `role_id`, never at `Role.external_id`.
 * That column has no notion of who said it, so a second provider releasing a group
 * literally named `staff` would grant this installation's staff role. It survives as
 * the seed for the convention provider's map and nothing reads it as a lookup key.
 *
 * Signing in through one provider must not strip what another granted, so what each
 * one granted is written onto its own identity row and the union is what the account
 * ends up holding. That is why `granted_role_ids` is a column and not derived: another
 * provider's grants have to survive a sign-in that never contacts it.
 */
final class ProviderRoles
{
    /**
     * The roles this provider grants for what it just released.
     *
     * @param  array<string, mixed>  $claims  Keyed by claim name: `groups` from userinfo,
     *                                        `packages` from the registration system, and
     *                                        anything else the provider publishes.
     * @return array<int, int>
     */
    public static function grantedBy(AuthProvider $provider, array $claims): array
    {
        $rules = array_values(array_filter(
            $provider->role_map ?? [],
            fn ($rule) => is_array($rule) && filled($rule['value'] ?? null) && filled($rule['role_id'] ?? null),
        ));

        $ids = [];

        foreach ($rules as $rule) {
            if (($rule['match'] ?? 'exact') === 'contains') {
                continue;
            }

            $released = array_map('strval', self::released($claims, $rule['claim'] ?? ''));

            if (in_array((string) $rule['value'], $released, true)) {
                $ids[] = (int) $rule['role_id'];
            }
        }

        /*
         * A package reads like "day-supersponsor-2026", so a rule claims the part it
         * recognises. Longest value first, or the sponsor rule swallows every
         * supersponsor package, and one role per released string once it has matched.
         */
        $contains = array_values(array_filter($rules, fn ($rule) => ($rule['match'] ?? 'exact') === 'contains'));
        usort($contains, fn ($a, $b) => strlen((string) $b['value']) <=> strlen((string) $a['value']));

        foreach ($contains as $rule) {
            foreach (self::released($claims, (string) ($rule['claim'] ?? '')) as $value) {
                $value = mb_strtolower((string) $value);

                // The first rule that matches this string is the longest one, so
                // anything shorter that also matches it is not a second answer.
                foreach ($contains as $candidate) {
                    if (($candidate['claim'] ?? null) !== ($rule['claim'] ?? null)) {
                        continue;
                    }

                    if (! str_contains($value, mb_strtolower((string) $candidate['value']))) {
                        continue;
                    }

                    if ($candidate === $rule) {
                        $ids[] = (int) $rule['role_id'];
                    }

                    break;
                }
            }
        }

        if ($provider->grants_baseline && ($baseline = self::baselineRoleId()) !== null) {
            $ids[] = $baseline;
        }

        return array_values(array_unique($ids));
    }

    /**
     * Every role this provider could grant, which is also every role it may take away.
     * The same ownership rule as before, moved from "the role carries an external id"
     * to "this provider's map names it".
     *
     * @return array<int, int>
     */
    public static function grantable(AuthProvider $provider): array
    {
        $ids = [];

        foreach ($provider->role_map ?? [] as $rule) {
            if (is_array($rule) && filled($rule['role_id'] ?? null)) {
                $ids[] = (int) $rule['role_id'];
            }
        }

        if ($provider->grants_baseline && ($baseline = self::baselineRoleId()) !== null) {
            $ids[] = $baseline;
        }

        return array_values(array_unique($ids));
    }

    /**
     * Reconcile an account's roles against every identity it holds.
     *
     * A role no provider's map names is never touched, however it was assigned.
     *
     * @param  array<int, int>  $releasing  Roles a provider that is being disconnected
     *                                      could grant, since its identity row is
     *                                      already gone by the time this runs.
     */
    public static function sync(User $user, array $releasing = []): void
    {
        $identities = $user->identities()->with('provider')->get();

        $union = [];
        $owned = $releasing;

        foreach ($identities as $identity) {
            foreach ((array) ($identity->granted_role_ids ?? []) as $id) {
                $union[] = (int) $id;
            }

            if ($identity->provider !== null) {
                $owned = array_merge($owned, self::grantable($identity->provider));
            }
        }

        $union = array_values(array_unique($union));
        $owned = array_values(array_unique($owned));

        $held = $user->roles()->pluck('roles.id')->map(fn ($id) => (int) $id)->all();

        foreach (array_diff($union, $held) as $id) {
            Role::find($id)?->assignTo($user, null);
        }

        $detach = array_diff(array_intersect($held, $owned), $union);

        if ($detach !== []) {
            $user->roles()->detach(array_values($detach));
        }
    }

    /**
     * Record what one sign-in granted, then recompute the account.
     *
     * @param  array<string, mixed>  $claims
     */
    public static function apply(UserIdentity $identity, AuthProvider $provider, array $claims): void
    {
        $identity->forceFill(['granted_role_ids' => self::grantedBy($provider, $claims)])->save();

        self::sync($identity->user);
    }

    public static function baselineRoleId(): ?int
    {
        $id = Role::query()->where('external_id', Role::BASELINE_EXTERNAL_ID)->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * @param  array<string, mixed>  $claims
     * @return array<int, mixed>
     */
    private static function released(array $claims, string $claim): array
    {
        if ($claim === '' || ! array_key_exists($claim, $claims)) {
            return [];
        }

        $value = $claims[$claim];

        return is_array($value) ? array_values($value) : [$value];
    }
}
