<?php

namespace Tests\Feature\Auth;

use App\Models\AuthProvider;
use App\Models\BrandingSetting;
use App\Models\User;
use App\Models\UserIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The one-shot migration that turns the installation's existing OIDC accounts into
 * identity rows. Nothing else may read the new tables until it has run, and a single
 * account failing to sign in afterwards is the failure this guards against.
 *
 * The migration is re-run against a cleared table rather than mocked: the assertion
 * that matters is what it writes, not that it was called.
 */
class IdentityBackfillTest extends TestCase
{
    use RefreshDatabase;

    private function rerun(): void
    {
        // The seeded row and its identities go first, because the migration inserts a
        // provider whose key and callback path are both unique.
        UserIdentity::query()->delete();
        AuthProvider::query()->delete();

        (require database_path('migrations/2026_08_30_100200_seed_legacy_auth_provider.php'))->up();
    }

    public function test_the_convention_provider_is_seeded_with_the_callback_it_already_has(): void
    {
        $provider = AuthProvider::legacy();

        $this->assertNotNull($provider);
        $this->assertSame('identity', $provider->key);
        $this->assertSame('oidc', $provider->driver);
        $this->assertSame(url('/auth/callback'), $provider->redirectUrl());
    }

    public function test_every_account_with_a_subject_gets_an_identity(): void
    {
        $withSubject = User::factory()->count(3)->create();
        $local = User::factory()->local()->create();

        $this->rerun();

        $provider = AuthProvider::legacy();

        foreach ($withSubject as $user) {
            $this->assertDatabaseHas('user_identities', [
                'user_id' => $user->id,
                'auth_provider_id' => $provider->id,
                'subject' => $user->sub,
            ]);
        }

        $this->assertSame(0, $local->identities()->count());
        // The column stays where it is: it is dropped in a later release, and until
        // then a parked chat ban and the account export both still read it.
        $this->assertNotNull($withSubject->first()->fresh()->sub);
    }

    /**
     * The four settings rows were the other half of the source, and a saved row always
     * won over the environment. They are read once and then deleted, so the table is
     * the only source afterwards.
     */
    public function test_the_saved_settings_rows_become_the_provider_row_and_go(): void
    {
        BrandingSetting::setValue('oidc_url', 'https://saved.example.org');
        BrandingSetting::setValue('oidc_client_id', 'saved-client');
        BrandingSetting::setValue('oidc_secret', 'saved-secret', null, true);
        BrandingSetting::setValue('auth_oidc', '1');

        $this->rerun();

        $provider = AuthProvider::legacy();

        $this->assertSame('https://saved.example.org', $provider->issuer_url);
        $this->assertSame('saved-client', $provider->client_id);
        $this->assertSame('saved-secret', $provider->client_secret);
        $this->assertTrue($provider->enabled);

        $this->assertSame(0, DB::table('branding_settings')
            ->whereIn('key', ['auth_oidc', 'oidc_url', 'oidc_client_id', 'oidc_secret'])
            ->count());
    }

    /**
     * The shape every installation that predates the settings pane is in: the provider
     * set in the environment and no `auth_oidc` row to read, because the pane that wrote
     * one never shipped to it. The switch defaults to `auth.modes.oauth2`, which ships
     * on, so the row comes out enabled and signing in survives the deploy.
     */
    public function test_a_provider_set_only_in_the_environment_is_seeded_enabled(): void
    {
        config([
            'services.oidc.url' => 'https://identity.example.org',
            'services.oidc.client_id' => 'the-client',
            'services.oidc.secret' => 'the-secret',
        ]);

        $this->rerun();

        $provider = AuthProvider::legacy();

        $this->assertTrue($provider->enabled);
        $this->assertSame('https://identity.example.org', $provider->issuer_url);
    }

    /**
     * A switch that was on with nothing behind it is a button that fails on the second
     * page, so the row comes out off rather than on-and-broken.
     */
    public function test_a_switch_with_no_endpoint_behind_it_is_seeded_off(): void
    {
        BrandingSetting::setValue('auth_oidc', '1');

        $this->rerun();

        $this->assertFalse(AuthProvider::legacy()->enabled);
    }
}
