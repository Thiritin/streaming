<?php

namespace App\Support;

use App\Models\AuthProvider;
use App\Models\BrandingSetting;
use App\Models\User;
use App\Support\Manage\Settings;
use Illuminate\Database\Eloquent\Collection;

/**
 * The ways into this installation, and whether each of them actually works.
 *
 * Password accounts and the self-registration on top of them are switches in the
 * settings registry; every other way in is a row in `auth_providers`, one per
 * provider. Guest access is `auth.required` inverted and is not a way in at all: it is
 * permission to browse without one. Every combination is valid.
 *
 * The two switches live in config/auth.php as shipped defaults and in the settings
 * table once an administrator has saved them, laid back over config by RuntimeConfig.
 * The providers are read straight off their table, because a row is not a config path
 * and there is nothing to overlay.
 *
 * Read the ways in here rather than off either source: a switched-on provider with no
 * endpoint behind it is a sign-in button that fails on the second page.
 */
final class AuthModes
{
    /**
     * The sign-in switches, whichever pane they are laid out on. Everything else about
     * a way in is a row in `auth_providers`.
     *
     * @var array<int, string>
     */
    public const KEYS = ['auth_required', 'auth_local', 'auth_oauth2', 'auth_registration'];

    /**
     * Every provider that is switched on and has an endpoint behind it, in button order.
     *
     * @return Collection<int, AuthProvider>
     */
    public static function providers(): Collection
    {
        return self::oauth2() ? AuthProvider::usable() : AuthProvider::usable()->take(0);
    }

    /**
     * The master switch over every provider. It sits beside password sign-in rather
     * than in the provider table because it is one installation-wide answer, where a
     * row's `enabled` says whether that one provider is set up and wanted. Off, no
     * provider is offered however many rows are switched on.
     */
    public static function oauth2(): bool
    {
        return (bool) config('auth.modes.oauth2');
    }

    /**
     * Whether any provider at all can be signed in through. Named `oidc` still because
     * that is what the sign-in screen has always called the non-password half.
     */
    public static function oidc(): bool
    {
        return self::providers()->isNotEmpty();
    }

    /**
     * Whether there is a provider to talk to at all, whatever its switch says.
     */
    public static function oidcConfigured(): bool
    {
        return AuthProvider::query()->get()->contains(fn (AuthProvider $provider) => $provider->isConfigured());
    }

    /**
     * Username and password accounts held by this installation.
     */
    public static function local(): bool
    {
        return (bool) config('auth.modes.local');
    }

    /**
     * Public self-registration, which is an option on local accounts rather than a
     * mode of its own: with nowhere to create an account, there is nothing to open.
     */
    public static function registration(): bool
    {
        return self::local() && (bool) config('auth.modes.registration');
    }

    /**
     * Whether a guest may browse without signing in. Delegates to auth.required so
     * the middleware, the playlist path and the player keep their single reader.
     */
    public static function guestAccess(): bool
    {
        return ! config('auth.required');
    }

    /**
     * Whether anyone can sign in at all. Guest access does not count.
     */
    public static function any(): bool
    {
        return self::oidc() || self::local();
    }

    /**
     * What the sign-in screen renders itself from.
     *
     * `oidc` survives as "is there a provider button at all", which is what the screen
     * lays itself out from; `providers` is what it draws one button per.
     *
     * @return array{oidc: bool, local: bool, registration: bool, guest: bool, providers: array<int, array{key: string, label: string, url: string}>}
     */
    public static function forFrontend(): array
    {
        $providers = self::providers();

        return [
            'oidc' => $providers->isNotEmpty(),
            'local' => self::local(),
            'registration' => self::registration(),
            'guest' => self::guestAccess(),
            'providers' => $providers->map(fn (AuthProvider $provider) => [
                'key' => $provider->key,
                'label' => $provider->label,
                'url' => $provider->redirectStartUrl(),
            ])->all(),
        ];
    }

    /**
     * Why the posted settings must not be saved, or null when they are safe.
     *
     * The settings pane sits behind admin.access, which needs somebody signed in, so
     * a save that switches the last usable mode off cannot be undone from a browser.
     * Two refusals: no mode left at all, and no mode left that an administrator can
     * actually use. `php artisan auth:local-admin` is the way back from the third
     * case neither can catch, a provider endpoint that stops answering.
     *
     * @param  array<string, mixed>  $values  The pane's posted values, flat keys.
     * @return array<string, string>|null Validation errors, keyed as the form posts.
     */
    public static function lockoutErrors(array $values): ?array
    {
        // Read off the table rather than off config: config is this process's overlay,
        // fixed since its last apply, so a mode another administrator switched off a
        // moment ago would still look on here. SettingsController holds the rows for
        // the length of the check and the save.
        $stored = self::stored();

        $localBefore = (bool) ($stored['auth_local'] ?? false);
        $oauthBefore = (bool) ($stored['auth_oauth2'] ?? self::oauth2());

        $localAfter = (bool) self::posted($values, 'auth_local', $localBefore);
        $oauthAfter = (bool) self::posted($values, 'auth_oauth2', $oauthBefore);

        // The providers are rows, so this pane cannot change them: what they stand at
        // now is what they stand at on both sides of the save.
        $rows = self::rawUsableProviderIds();

        // The switch the operator just touched is where the message belongs. With both
        // moved at once the providers are the more consequential half.
        $field = $oauthAfter !== $oauthBefore ? 'auth_oauth2' : 'auth_local';

        return self::worsens(
            $oauthBefore ? $rows : [],
            $localBefore,
            $oauthAfter ? $rows : [],
            $localAfter,
            $field,
        );
    }

    /**
     * Why a provider row must not be switched off or deleted, or null when it is safe.
     *
     * The same check as the settings save and for the same reason: disabling the last
     * enabled provider from its own page is a lockout the settings pane never sees.
     */
    public static function providerLockout(AuthProvider $provider, bool $enabledAfter): ?string
    {
        $local = (bool) (self::stored()['auth_local'] ?? false);
        $oauth2 = self::oauth2();

        $before = self::rawUsableProviderIds();
        $after = array_values(array_diff($before, [$provider->id]));

        if ($enabledAfter) {
            $after[] = $provider->id;
        }

        $errors = self::worsens(
            $oauth2 ? $before : [],
            $local,
            $oauth2 ? $after : [],
            $local,
            'auth_local',
        );

        return $errors === null ? null : reset($errors);
    }

    /**
     * Why the settings must not be reset, or null when it is safe.
     *
     * Reset deletes every saved row, so what is left is the config as shipped. Provider
     * rows are not settings rows and survive it, which is why they are counted as they
     * stand rather than as they were shipped - there is no shipped answer for a row.
     */
    public static function resetLockout(): ?string
    {
        $rows = self::rawUsableProviderIds();

        // What stands now comes off the table for the same reason the save path reads
        // it there: config is this process's overlay and can be a save behind.
        $errors = self::worsens(
            self::oauth2() ? $rows : [],
            (bool) (self::stored()['auth_local'] ?? self::local()),
            RuntimeConfig::shipped('auth.modes.oauth2') ? $rows : [],
            (bool) RuntimeConfig::shipped('auth.modes.local'),
            'auth_local',
        );

        return $errors === null ? null : 'No administrator could sign in afterwards.';
    }

    /**
     * Whether at least one account that can reach /manage could still get in.
     *
     * Counted through ManageAccess, which is also what the `access-manage` gate is
     * defined as: counting a narrower set than the gate lets in would refuse every
     * save on an installation whose roles carry `filament.access`, which is most of
     * the ones that have been running a while.
     */
    public static function administratorCanSignIn(bool $providers, bool $local): bool
    {
        return self::administratorCanSignInWith($providers ? self::rawUsableProviderIds() : [], $local);
    }

    /**
     * The same question against a named set of providers, which is what the provider
     * CRUD asks: "if this row went, would anybody be left".
     *
     * An account signs in through a provider by holding an identity on it. The legacy
     * `sub` counts for as long as the column is there, because a seeder or a fixture
     * can still write one without an identity row.
     *
     * @param  array<int, int>  $providerIds
     */
    public static function administratorCanSignInWith(array $providerIds, bool $local): bool
    {
        if ($providerIds === [] && ! $local) {
            return false;
        }

        $roleIds = ManageAccess::roleIds();

        if ($roleIds->isEmpty()) {
            return false;
        }

        $legacy = AuthProvider::legacy();
        $legacyOn = $legacy !== null && in_array($legacy->id, $providerIds, true);

        return User::query()
            ->whereHas('roles', fn ($query) => $query->whereIn('roles.id', $roleIds))
            ->where(function ($query) use ($providerIds, $local, $legacyOn) {
                if ($providerIds !== []) {
                    $query->orWhereHas(
                        'identities',
                        fn ($identities) => $identities->whereIn('auth_provider_id', $providerIds),
                    );
                }

                if ($legacyOn) {
                    $query->orWhereNotNull('sub');
                }

                if ($local) {
                    $query->orWhereNotNull('password');
                }
            })
            ->exists();
    }

    /**
     * The shared refusal, so the settings save and the provider CRUD cannot drift.
     *
     * Only a change that makes things worse is refused. Two installations hear
     * nothing: one that already has no way in, where there is nothing left to protect
     * and a refusal would only stop the operator saving the site name from the same
     * pane, and one whose change leaves the answer where it was.
     *
     * Two questions, not one. "Is a mode on at all" and "can an administrator use one"
     * fail differently, and an installation can be on the wrong side of either.
     *
     * @param  array<int, int>  $providersBefore
     * @param  array<int, int>  $providersAfter
     * @param  string  $field  The switch the message belongs against.
     * @return array<string, string>|null
     */
    private static function worsens(
        array $providersBefore,
        bool $localBefore,
        array $providersAfter,
        bool $localAfter,
        string $field,
    ): ?array {
        $wayInBefore = $providersBefore !== [] || $localBefore;
        $wayInAfter = $providersAfter !== [] || $localAfter;

        if ($wayInBefore && ! $wayInAfter) {
            return ['values.'.$field => 'Leave at least one sign-in mode on. Guest access is not one.'];
        }

        if (! $wayInAfter) {
            return null;
        }

        if (! self::administratorCanSignInWith($providersBefore, $localBefore)) {
            return null;
        }

        if (self::administratorCanSignInWith($providersAfter, $localAfter)) {
            return null;
        }

        return ['values.'.$field => 'No administrator can sign in with what this leaves on.'];
    }

    /**
     * Every provider that is set up and switched on, ignoring the master switch. What
     * the lockout arithmetic is done in, because the switch is the other term in it.
     *
     * @return array<int, int>
     */
    private static function rawUsableProviderIds(): array
    {
        return AuthProvider::usable()->pluck('id')->all();
    }

    /**
     * The keys the sign-in pane owns, which are also the rows a save holds a lock over.
     *
     * @return array<int, string>
     */
    public static function settingKeys(): array
    {
        return array_column(self::fields(), 'key');
    }

    /**
     * Whether a pane carries the sign-in switches, and so has to be saved under the
     * lock rather than straight through.
     */
    public static function ownsPane(string $key): bool
    {
        return ($group = self::group()) !== null && ($group['key'] ?? null) === $key;
    }

    /**
     * The sign-in switches as declared in the registry.
     *
     * Named rather than taken whole from the pane they sit on: since the merge they
     * share it with the site name, the provider pages and the login copy, and none of
     * those are rows this has any business locking or reading.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function fields(): array
    {
        $group = self::group();

        if ($group === null) {
            return [];
        }

        return array_values(array_filter(
            Settings::declaredFields($group),
            fn (array $field) => in_array($field['key'] ?? null, self::KEYS, true),
        ));
    }

    /**
     * The group that carries the sign-in switches. Found by the switch it holds rather
     * than by a group key, because which pane they live on is a matter of layout: they
     * were a pane of their own and are now a card on the sign-in one.
     *
     * @return array<string, mixed>|null
     */
    private static function group(): ?array
    {
        foreach (config('settings.groups', []) as $group) {
            foreach (Settings::declaredFields($group) as $field) {
                if (($field['key'] ?? null) === 'auth_local') {
                    return $group;
                }
            }
        }

        return null;
    }

    /**
     * What each of the pane's fields stands at right now, straight from the table and
     * past every cache, falling back to the shipped default where no row exists.
     *
     * @return array<string, mixed>
     */
    private static function stored(): array
    {
        $fields = self::fields();

        if ($fields === []) {
            return [];
        }

        $rows = BrandingSetting::query()
            ->whereIn('key', array_column($fields, 'key'))
            ->get()
            ->keyBy('key');

        $current = [];

        foreach ($fields as $field) {
            $row = $rows->get($field['key']);

            $value = $row === null
                ? null
                : BrandingSetting::plain($row->value, (bool) ($row->encrypted ?? false), $field['key']);

            if ($value === null || $value === '') {
                $current[$field['key']] = Settings::defaultOf($field);

                continue;
            }

            $current[$field['key']] = ($field['type'] ?? null) === 'toggle'
                ? Settings::toBool($value)
                : $value;
        }

        return $current;
    }

    /**
     * A posted value, or what stands today for a field the pane did not send.
     *
     * Only ever called for `auth_local` and `auth_oauth2`, and that is a condition
     * rather than a coincidence. A registry field may declare `invert`, in which case
     * the pane posts the opposite of what the column holds and Settings::save() turns
     * it back on the way in - `auth_required` does, being read to the operator as
     * "guest access". `stored()` reads the column, so comparing a posted inverted value
     * against it here would silently reverse the meaning of the change. Anything
     * inverted has to be un-inverted before it reaches this class.
     *
     * @param  array<string, mixed>  $values
     */
    private static function posted(array $values, string $key, mixed $current): mixed
    {
        return array_key_exists($key, $values) ? $values[$key] : $current;
    }
}
