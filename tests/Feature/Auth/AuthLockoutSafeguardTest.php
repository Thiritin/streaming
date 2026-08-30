<?php

namespace Tests\Feature\Auth;

use App\Models\AuthProvider;
use App\Models\BrandingSetting;
use App\Models\Role;
use App\Models\User;
use App\Support\AuthModes;
use App\Support\Manage\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\ConfiguresAuthProviders;
use Tests\TestCase;

/**
 * The settings pane and the provider pages both sit behind admin.access, so a change
 * that takes the last usable way in away cannot be undone from a browser. Four things
 * stop that: the pane refuses to leave no mode on, it refuses to leave none an
 * administrator can use, the provider pages are held to the same check on their update
 * and their delete, and auth:local-admin is the way back when none could have known.
 */
class AuthLockoutSafeguardTest extends TestCase
{
    use ConfiguresAuthProviders;
    use RefreshDatabase;

    private User $admin;

    private AuthProvider $provider;

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

        $this->provider = $this->legacyProvider();

        // An account signed in through the provider, which is what every installation
        // starts with.
        $this->admin = User::factory()->create();
        $this->admin->roles()->attach($role);
        $this->connect($this->admin, $this->provider);

        config(['auth.modes.local' => false]);
    }

    /**
     * The whole pane as it currently stands, which is what an untouched form posts.
     *
     * @param  array<string, mixed>  $values
     */
    private function save(array $values): TestResponse
    {
        $fields = app(Settings::class)->group('identity')['fields'];

        $current = [];

        foreach ($fields as $field) {
            $current[$field['key']] = $field['type'] === 'password' ? '' : $field['value'];
        }

        return $this->actingAs($this->admin)->from('/manage/settings/identity')->put('/manage/settings/identity', [
            'values' => $values + $current,
        ]);
    }

    private function saveProvider(array $values): TestResponse
    {
        return $this->actingAs($this->admin)
            ->from(route('manage.providers.edit', $this->provider))
            ->put(route('manage.providers.update', $this->provider), $values + [
                'driver' => $this->provider->driver,
                'key' => $this->provider->key,
                'label' => $this->provider->label,
                'client_id' => $this->provider->client_id,
                'client_secret' => Settings::MASK_SECRET,
                'issuer_url' => $this->provider->issuer_url,
                'enabled' => true,
                'order' => 0,
                'grants_baseline' => true,
            ]);
    }

    public function test_a_save_that_leaves_no_sign_in_mode_is_refused(): void
    {
        BrandingSetting::setValue('auth_local', '1');
        $this->provider->update(['enabled' => false]);

        $this->save(['auth_local' => false])
            ->assertSessionHasErrors('values.auth_local');

        $this->assertSame('1', BrandingSetting::where('key', 'auth_local')->value('value'));
    }

    /**
     * Guest access is permission to browse without signing in, not a way in.
     */
    public function test_guest_access_does_not_count_as_a_sign_in_mode(): void
    {
        BrandingSetting::setValue('auth_local', '1');
        $this->provider->update(['enabled' => false]);

        $this->save(['auth_required' => false, 'auth_local' => false])
            ->assertSessionHasErrors('values.auth_local');
    }

    /**
     * Switching the last provider off from its own page is a lockout the settings pane
     * never sees, which is why the check runs there too.
     */
    public function test_switching_the_last_provider_off_is_refused(): void
    {
        $this->saveProvider(['enabled' => false])->assertRedirect();

        $this->assertTrue($this->provider->fresh()->enabled);
    }

    /**
     * Clearing the endpoint is the same lockout by another route: the row stays on and
     * the button stops working.
     */
    public function test_clearing_the_last_providers_endpoint_is_refused(): void
    {
        $this->saveProvider(['issuer_url' => null, 'client_id' => null])->assertRedirect();

        $this->assertSame('streaming', $this->provider->fresh()->client_id);
    }

    /**
     * The code exchange needs the secret, so clearing that one field breaks sign-in
     * exactly the way clearing the URL does.
     */
    public function test_clearing_the_last_providers_secret_is_refused(): void
    {
        $this->saveProvider(['client_secret' => Settings::CLEAR_SECRET])->assertRedirect();

        $this->assertSame('a-client-secret', $this->provider->fresh()->client_secret);
    }

    /**
     * A provider nobody signs in through can go; one accounts hold identities on
     * cannot, because the foreign key cascades and would take them all with it.
     */
    public function test_a_provider_accounts_sign_in_through_cannot_be_deleted(): void
    {
        $this->actingAs($this->admin)
            ->from(route('manage.providers.edit', $this->provider))
            ->delete(route('manage.providers.destroy', $this->provider))
            ->assertRedirect();

        $this->assertDatabaseHas('auth_providers', ['id' => $this->provider->id]);
        $this->assertSame(1, $this->admin->fresh()->identities()->count());
    }

    public function test_an_unused_provider_can_be_deleted(): void
    {
        $spare = AuthProvider::factory()->create(['key' => 'spare']);

        $this->actingAs($this->admin)
            ->delete(route('manage.providers.destroy', $spare))
            ->assertRedirect(route('manage.providers.index'));

        $this->assertDatabaseMissing('auth_providers', ['id' => $spare->id]);
    }

    public function test_switching_off_the_only_mode_the_administrators_hold_is_refused(): void
    {
        // Password accounts are being switched on, but every administrator signs in
        // through the provider, so nobody would be able to use them.
        $this->save(['auth_local' => true])->assertSessionHasNoErrors();

        $this->saveProvider(['enabled' => false])->assertRedirect();

        $this->assertTrue($this->provider->fresh()->enabled);
    }

    public function test_the_same_change_goes_through_once_an_administrator_holds_a_password(): void
    {
        $local = User::factory()->local()->create();
        $local->roles()->attach(Role::where('slug', 'admin')->first());

        $this->save(['auth_local' => true])->assertSessionHasNoErrors();

        $this->saveProvider(['enabled' => false])->assertRedirect();

        $this->assertFalse($this->provider->fresh()->enabled);
    }

    /**
     * Reset deletes every saved row, so it puts the shipped modes back. On an
     * installation run on local accounts alone that is the same lockout.
     */
    public function test_resetting_the_settings_is_refused_when_it_would_lock_everyone_out(): void
    {
        $this->provider->update(['enabled' => false]);

        BrandingSetting::setValue('auth_local', '1');
        $local = User::factory()->local()->create();
        $local->roles()->attach(Role::where('slug', 'admin')->first());
        $this->admin->delete();

        $this->actingAs($local)->from('/manage/settings')->post('/manage/settings/reset');

        $this->assertSame('1', BrandingSetting::where('key', 'auth_local')->value('value'));
    }

    /**
     * The other half of that door is now closed for good: a provider is a row, and
     * reset only deletes settings rows, so it cannot take the way in away any more.
     */
    public function test_resetting_leaves_the_providers_alone(): void
    {
        $this->actingAs($this->admin)->from('/manage/settings')->post('/manage/settings/reset');

        $this->assertTrue($this->provider->fresh()->enabled);
        $this->assertNull(AuthModes::resetLockout());
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
        BrandingSetting::setValue('auth_local', '1');

        $this->assertNull(AuthModes::providerLockout($this->provider, false));
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
        // What a concurrent save leaves behind: password accounts off in the table
        // while this process's config still says they are on. Switching the last
        // provider off has to be refused against the table's answer, not this one's.
        BrandingSetting::setValue('auth_local', '0');
        config(['auth.modes.local' => true]);

        $this->saveProvider(['enabled' => false])->assertRedirect();

        $this->assertTrue($this->provider->fresh()->enabled);
    }

    /**
     * The master switch is a way in, so it is held to the same rule as password
     * sign-in: it cannot be the last one switched off.
     */
    public function test_switching_oauth2_off_while_it_is_the_only_method_is_refused(): void
    {
        $this->save(['auth_oauth2' => false])
            ->assertSessionHasErrors('values.auth_oauth2');

        $this->assertTrue(AuthModes::oauth2());
    }

    public function test_switching_oauth2_off_is_allowed_once_an_administrator_holds_a_password(): void
    {
        $local = User::factory()->local()->create();
        $local->roles()->attach(Role::where('slug', 'admin')->first());

        $this->save(['auth_local' => true])->assertSessionHasNoErrors();
        $this->save(['auth_oauth2' => false])->assertSessionHasNoErrors();

        $this->assertSame('0', BrandingSetting::where('key', 'auth_oauth2')->value('value'));
    }

    /**
     * Off, no provider is a way in however many rows are switched on, so the last row
     * can be switched off freely and the sign-in screen offers nothing.
     */
    public function test_the_master_switch_takes_every_provider_with_it(): void
    {
        BrandingSetting::setValue('auth_local', '1');
        $local = User::factory()->local()->create();
        $local->roles()->attach(Role::where('slug', 'admin')->first());

        config(['auth.modes.oauth2' => false]);

        $this->assertTrue($this->provider->fresh()->isUsable());
        $this->assertTrue(AuthModes::providers()->isEmpty());
        $this->assertFalse(AuthModes::forFrontend()['oidc']);

        $this->assertNull(AuthModes::providerLockout($this->provider, false));
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
     * An account a provider owns is left alone: its password lives there, and a second
     * way into it is not something a recovery command should invent.
     */
    public function test_the_escape_hatch_leaves_a_provider_account_alone(): void
    {
        $provider = User::factory()->create(['email' => 'operator@example.org']);
        $this->connect($provider, $this->provider, 'operator-subject');

        $this->artisan('auth:local-admin', [
            'email' => 'operator@example.org',
            '--password' => 'a-long-enough-password',
        ])->assertSuccessful();

        $this->assertNull($provider->fresh()->password);
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
