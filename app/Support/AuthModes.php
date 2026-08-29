<?php

namespace App\Support;

use App\Models\BrandingSetting;
use App\Models\User;
use App\Support\Manage\Settings;

/**
 * The ways into this installation, and whether each of them actually works.
 *
 * Three independent switches - the identity provider, local username and password
 * accounts, and public self-registration on top of the second - plus guest access,
 * which is `auth.required` inverted and is not a way in at all: it is permission to
 * browse without one. Every combination is valid.
 *
 * The switches live in config/auth.php as shipped defaults and in the settings table
 * once an administrator has saved them, laid back over config by RuntimeConfig. Read
 * them here rather than off config(): `oidc` on its own is a switch, and a provider
 * with no endpoint behind it is a sign-in button that fails on the second page.
 */
final class AuthModes
{
    /**
     * The identity provider: switched on, and with somewhere to send people.
     */
    public static function oidc(): bool
    {
        return (bool) config('auth.modes.oidc') && self::oidcConfigured();
    }

    /**
     * Whether there is a provider to talk to at all, whatever the switch says.
     */
    public static function oidcConfigured(): bool
    {
        return filled(config('services.oidc.url')) && filled(config('services.oidc.client_id'));
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
     * @return array{oidc: bool, local: bool, registration: bool, guest: bool}
     */
    public static function forFrontend(): array
    {
        return [
            'oidc' => self::oidc(),
            'local' => self::local(),
            'registration' => self::registration(),
            'guest' => self::guestAccess(),
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

        $local = (bool) self::posted($values, 'auth_local', $stored['auth_local'] ?? false);

        // All three connection fields, the secret included: the code exchange needs it,
        // so clearing it alone breaks sign-in exactly the way clearing the URL does.
        $oidc = (bool) self::posted($values, 'auth_oidc', $stored['auth_oidc'] ?? false)
            && filled(self::posted($values, 'oidc_url', $stored['oidc_url'] ?? null))
            && filled(self::posted($values, 'oidc_client_id', $stored['oidc_client_id'] ?? null))
            && filled(self::postedSecret($values, 'oidc_secret', $stored['oidc_secret'] ?? null));

        if (! $oidc && ! $local) {
            return ['values.auth_local' => 'Leave at least one sign-in mode on. Guest access is not one.'];
        }

        if (self::administratorCanSignIn($oidc, $local)) {
            return null;
        }

        return [
            'values.'.($local ? 'auth_oidc' : 'auth_local') => 'No administrator can sign in with what this leaves on.',
        ];
    }

    /**
     * Why the settings must not be reset, or null when it is safe.
     *
     * Reset deletes every saved row, so what is left is the config as shipped. An
     * installation whose provider details were typed into the pane rather than set in
     * the environment has nothing behind them once the rows go, which is the same
     * lockout the save path refuses and is why this is measured against the shipped
     * config rather than against what is in force now.
     */
    public static function resetLockout(): ?string
    {
        $oidc = (bool) RuntimeConfig::shipped('auth.modes.oidc')
            && filled(RuntimeConfig::shipped('services.oidc.url'))
            && filled(RuntimeConfig::shipped('services.oidc.client_id'))
            && filled(RuntimeConfig::shipped('services.oidc.secret'));

        if (self::administratorCanSignIn($oidc, (bool) RuntimeConfig::shipped('auth.modes.local'))) {
            return null;
        }

        return 'No administrator could sign in afterwards.';
    }

    /**
     * Whether at least one account that can reach /manage could still get in.
     *
     * Counted through ManageAccess, which is also what the `access-manage` gate is
     * defined as: counting a narrower set than the gate lets in would refuse every
     * save on an installation whose roles carry `filament.access`, which is most of
     * the ones that have been running a while.
     *
     * An OIDC account is one with a subject; a local one is an account with a
     * password. An account can be both.
     */
    public static function administratorCanSignIn(bool $oidc, bool $local): bool
    {
        if (! $oidc && ! $local) {
            return false;
        }

        $roleIds = ManageAccess::roleIds();

        if ($roleIds->isEmpty()) {
            return false;
        }

        return User::query()
            ->whereHas('roles', fn ($query) => $query->whereIn('roles.id', $roleIds))
            ->where(function ($query) use ($oidc, $local) {
                if ($oidc) {
                    $query->orWhereNotNull('sub');
                }

                if ($local) {
                    $query->orWhereNotNull('password');
                }
            })
            ->exists();
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
     * The pane's fields as declared in the registry.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function fields(): array
    {
        foreach (config('settings.groups', []) as $group) {
            if (($group['key'] ?? null) === 'auth') {
                return $group['fields'] ?? [];
            }
        }

        return [];
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
     * @param  array<string, mixed>  $values
     */
    private static function posted(array $values, string $key, mixed $current): mixed
    {
        return array_key_exists($key, $values) ? $values[$key] : $current;
    }

    /**
     * The same for a write-only field, which never receives the stored value and so
     * posts the mask to mean "leave it" and the sentinel to mean "delete it".
     *
     * @param  array<string, mixed>  $values
     */
    private static function postedSecret(array $values, string $key, mixed $current): mixed
    {
        if (! array_key_exists($key, $values)) {
            return $current;
        }

        $posted = is_string($values[$key]) ? trim($values[$key]) : $values[$key];

        if ($posted === Settings::CLEAR_SECRET) {
            return null;
        }

        return ($posted === null || $posted === '' || $posted === Settings::MASK_SECRET) ? $current : $posted;
    }
}
