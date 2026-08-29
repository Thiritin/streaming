<?php

namespace Tests\Feature\Auth;

use App\Models\BrandingSetting;
use App\Models\Role;
use App\Models\User;
use App\Support\AuthModes;
use App\Support\Manage\Settings;
use App\Support\RuntimeConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The settings pane sits behind admin.access, so a save that takes the last usable
 * sign-in mode away cannot be undone from a browser. Three things stop that: the
 * pane refuses to leave no mode on, it refuses to leave none an administrator can
 * use, and auth:local-admin is the way back when neither could have known.
 */
class AuthLockoutSafeguardTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $role = Role::create([
            'name' => 'Administrator',
            'slug' => 'admin',
            'permissions' => ['admin.access'],
            'priority' => 100,
        ]);

        // An identity-provider account, which is what every installation starts with.
        $this->admin = User::factory()->create();
        $this->admin->roles()->attach($role);

        config([
            'auth.modes.oidc' => true,
            'auth.modes.local' => false,
            'services.oidc.url' => 'https://identity.example.org',
            'services.oidc.client_id' => 'streaming',
            'services.oidc.secret' => 'a-client-secret',
        ]);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function save(array $values): TestResponse
    {
        return $this->actingAs($this->admin)->from('/manage/settings/auth')->put('/manage/settings/auth', [
            'values' => $values + [
                'auth_required' => true,
                'auth_local' => false,
                'auth_registration' => false,
                'auth_oidc' => true,
                'oidc_url' => 'https://identity.example.org',
                'oidc_client_id' => 'streaming',
                'oidc_secret' => Settings::MASK_SECRET,
            ],
        ]);
    }

    public function test_a_save_that_leaves_no_sign_in_mode_is_refused(): void
    {
        $this->save(['auth_oidc' => false, 'auth_local' => false])
            ->assertSessionHasErrors('values.auth_local');

        $this->assertNull(BrandingSetting::where('key', 'auth_oidc')->first());
        $this->assertTrue((bool) config('auth.modes.oidc'));
    }

    /**
     * Guest access is permission to browse without signing in, not a way in.
     */
    public function test_guest_access_does_not_count_as_a_sign_in_mode(): void
    {
        $this->save(['auth_required' => false, 'auth_oidc' => false, 'auth_local' => false])
            ->assertSessionHasErrors('values.auth_local');
    }

    /**
     * Clearing the endpoint is the same lockout by another route: the toggle stays
     * on and the button stops working.
     */
    public function test_a_save_that_empties_the_provider_endpoint_is_refused(): void
    {
        $this->save(['oidc_url' => null, 'auth_local' => false])
            ->assertSessionHasErrors('values.auth_local');
    }

    /**
     * The code exchange needs the secret, so clearing that one field breaks sign-in
     * exactly the way clearing the URL does.
     */
    public function test_a_save_that_clears_the_provider_secret_is_refused(): void
    {
        $this->save(['oidc_secret' => Settings::CLEAR_SECRET, 'auth_local' => false])
            ->assertSessionHasErrors('values.auth_local');
    }

    public function test_switching_off_the_only_mode_the_administrators_hold_is_refused(): void
    {
        // Password accounts are being switched on, but every administrator is an
        // identity-provider account, so nobody would be able to use them.
        $this->save(['auth_local' => true, 'auth_oidc' => false])
            ->assertSessionHasErrors('values.auth_oidc');
    }

    public function test_the_same_save_goes_through_once_an_administrator_holds_a_password(): void
    {
        $local = User::factory()->local()->create();
        $local->roles()->attach(Role::where('slug', 'admin')->first());

        $this->save(['auth_local' => true, 'auth_oidc' => false])
            ->assertSessionHasNoErrors();

        $this->assertSame('0', BrandingSetting::where('key', 'auth_oidc')->value('value'));
        $this->assertSame('1', BrandingSetting::where('key', 'auth_local')->value('value'));
    }

    /**
     * Reset deletes every saved row, so it puts the shipped modes back. On an
     * installation run on local accounts alone that is the same lockout.
     */
    public function test_resetting_the_settings_is_refused_when_it_would_lock_everyone_out(): void
    {
        config(['services.oidc.url' => null, 'services.oidc.client_id' => null, 'services.oidc.secret' => null]);

        BrandingSetting::setValue('auth_local', '1');
        $local = User::factory()->local()->create();
        $local->roles()->attach(Role::where('slug', 'admin')->first());
        $this->admin->delete();

        $this->actingAs($local)->from('/manage/settings')->post('/manage/settings/reset');

        $this->assertSame('1', BrandingSetting::where('key', 'auth_local')->value('value'));
    }

    /**
     * The other half of the same door: an installation whose provider details were
     * typed into the pane rather than set in the environment. The rows go in the same
     * reset and there is nothing behind them.
     */
    public function test_resetting_is_refused_when_the_provider_details_are_saved_rows(): void
    {
        // What an installation configured entirely from the pane looks like: nothing
        // in the environment, three rows, and the overlay laid back over config.
        config(['services.oidc.url' => null, 'services.oidc.client_id' => null, 'services.oidc.secret' => null]);

        BrandingSetting::setValue('oidc_url', 'https://identity.example.org');
        BrandingSetting::setValue('oidc_client_id', 'streaming');
        BrandingSetting::setValue('oidc_secret', 'a-client-secret', null, true);

        RuntimeConfig::apply();

        $this->assertTrue(AuthModes::oidcConfigured());

        $this->actingAs($this->admin)->from('/manage/settings')->post('/manage/settings/reset');

        $this->assertSame('streaming', BrandingSetting::where('key', 'oidc_client_id')->value('value'));
    }

    /**
     * The gate lets `filament.access` and the `admin` slug through as well, and
     * `filament.access` is what long-running installations actually store. Counting a
     * narrower set refused every save while real operators were using the panel.
     */
    public function test_an_administrator_by_filament_access_is_counted(): void
    {
        $role = Role::create([
            'name' => 'Panel',
            'slug' => 'panel',
            'permissions' => ['filament.access'],
            'priority' => 50,
        ]);

        $operator = User::factory()->local()->create();
        $operator->roles()->attach($role);

        // No admin.access anywhere, and the only account that can reach the panel is a
        // local one, so switching the provider off has to be allowed.
        $this->admin->roles()->detach();

        $this->save(['auth_local' => true, 'auth_oidc' => false])
            ->assertSessionHasNoErrors();

        $this->assertTrue(AuthModes::administratorCanSignIn(false, true));
    }

    public function test_an_administrator_by_the_admin_role_alone_is_counted(): void
    {
        // The slug opens the panel on its own, so a role carrying no permission at
        // all still counts.
        $role = Role::where('slug', 'admin')->first();
        $role->update(['permissions' => []]);

        $this->admin->roles()->detach();
        $operator = User::factory()->local()->create();
        $operator->roles()->attach($role);

        $this->assertTrue(AuthModes::administratorCanSignIn(false, true));
    }

    /**
     * The check reads the table, not this process's config overlay, so a mode another
     * administrator switched off a moment ago is seen.
     */
    public function test_the_check_reads_the_stored_state_rather_than_stale_config(): void
    {
        // What a concurrent save leaves behind: the provider off in the table while
        // this process's config still says it is on.
        BrandingSetting::setValue('auth_oidc', '0');

        $this->assertTrue((bool) config('auth.modes.oidc'));

        // Posting only the password toggle off must now be refused, because the
        // provider is already off in the table.
        $this->actingAs($this->admin)->from('/manage/settings/auth')->put('/manage/settings/auth', [
            'values' => ['auth_local' => false],
        ])->assertSessionHasErrors('values.auth_local');
    }

    public function test_the_artisan_escape_hatch_creates_a_local_administrator_and_switches_the_mode_on(): void
    {
        $this->artisan('auth:local-admin', [
            'email' => 'operator@example.org',
            '--password' => 'a-long-enough-password',
        ])->assertSuccessful();

        $user = User::where('email', 'operator@example.org')->firstOrFail();

        $this->assertNull($user->sub);
        $this->assertTrue($user->hasPermission('admin.access'));
        $this->assertSame('1', BrandingSetting::where('key', 'auth_local')->value('value'));

        $this->assertTrue(Hash::check('a-long-enough-password', $user->password));
    }

    public function test_the_escape_hatch_promotes_an_existing_local_account_rather_than_duplicating_it(): void
    {
        $existing = User::factory()->local()->create(['email' => 'operator@example.org']);

        $this->artisan('auth:local-admin', [
            'email' => 'operator@example.org',
            '--password' => 'a-long-enough-password',
        ])->assertSuccessful();

        $this->assertSame(1, User::where('email', 'operator@example.org')->count());
        $this->assertTrue($existing->fresh()->hasPermission('admin.access'));
    }

    /**
     * An account the identity provider owns is left alone: its password lives there,
     * and a second way into it is not something a recovery command should invent.
     */
    public function test_the_escape_hatch_leaves_an_identity_provider_account_alone(): void
    {
        $oidc = User::factory()->create(['email' => 'operator@example.org']);

        $this->artisan('auth:local-admin', [
            'email' => 'operator@example.org',
            '--password' => 'a-long-enough-password',
        ])->assertSuccessful();

        $this->assertNull($oidc->fresh()->password);
        $this->assertSame(2, User::where('email', 'operator@example.org')->count());
    }

    public function test_the_escape_hatch_refuses_a_short_password(): void
    {
        $this->artisan('auth:local-admin', [
            'email' => 'operator@example.org',
            '--password' => 'short',
        ])->assertFailed();

        $this->assertNull(User::where('email', 'operator@example.org')->first());
    }
}
