<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * The identity provider this installation already had, as a row.
 *
 * Its details came from two places - the environment through config/services.php, and
 * the four `oidc_*` settings rows - and a saved row always won, so that is the order
 * read here. The settings rows are deleted afterwards: the table is the only source
 * from here on, and a second one could only disagree.
 *
 * Then every account with a subject gets an identity on it. `users.sub` is unique, so
 * neither of the new uniques can collide and the backfill cannot fail on a live
 * database - which is the whole point of doing it before anything reads the tables.
 * The column stays where it is and is dropped in a later release.
 */
return new class extends Migration
{
    private const KEYS = ['auth_oidc', 'oidc_url', 'oidc_client_id', 'oidc_secret'];

    public function up(): void
    {
        $saved = $this->savedSettings();

        $url = $saved['oidc_url'] ?? config('services.oidc.url');
        $clientId = $saved['oidc_client_id'] ?? config('services.oidc.client_id');
        $secret = $saved['oidc_secret'] ?? config('services.oidc.secret');

        $switch = $saved['auth_oidc'] ?? null;
        $enabled = $switch === null
            ? (bool) config('auth.modes.oidc')
            : in_array($switch, ['1', 'true', 'on', 'yes'], true);

        $id = DB::table('auth_providers')->insertGetId([
            'driver' => 'oidc',
            'key' => 'identity',
            'label' => $this->label(),
            'client_id' => $clientId,
            'client_secret' => $secret === null ? null : Crypt::encryptString($secret),
            'issuer_url' => $url,
            // That URI is registered as an allowed redirect at the provider, so it is
            // kept rather than moved onto the generated three-segment one.
            'redirect_path' => '/auth/callback',
            'enabled' => $enabled && filled($url) && filled($clientId),
            'order' => 0,
            'grants_baseline' => true,
            'packages_url' => config('services.attsrv.url'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('users')
            ->whereNotNull('sub')
            ->orderBy('id')
            ->chunkById(500, function ($users) use ($id) {
                DB::table('user_identities')->insert($users->map(fn ($user) => [
                    'user_id' => $user->id,
                    'auth_provider_id' => $id,
                    'subject' => $user->sub,
                    'email' => $user->email ?? null,
                    'name' => $user->name ?? null,
                    'avatar' => $user->avatar ?? null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ])->all());
            });

        DB::table('branding_settings')->whereIn('key', self::KEYS)->delete();
    }

    public function down(): void
    {
        DB::table('user_identities')->delete();
        DB::table('auth_providers')->where('key', 'identity')->delete();
    }

    /**
     * The saved settings rows, decrypted, keyed as the pane posted them.
     *
     * @return array<string, string|null>
     */
    private function savedSettings(): array
    {
        $rows = DB::table('branding_settings')->whereIn('key', self::KEYS)->get();

        $saved = [];

        foreach ($rows as $row) {
            $value = $row->value;

            if ($value !== null && ($row->encrypted ?? false)) {
                try {
                    $value = Crypt::decryptString($value);
                } catch (Throwable) {
                    // A rotated APP_KEY: the environment's value is the fallback,
                    // and an unreadable secret must not stop the migration.
                    continue;
                }
            }

            $saved[$row->key] = $value === '' ? null : $value;
        }

        return $saved;
    }

    private function label(): string
    {
        $saved = DB::table('branding_settings')->where('key', 'identity_name')->value('value');

        return $saved ?: (config('branding.identity_name') ?: 'Identity provider');
    }
};
