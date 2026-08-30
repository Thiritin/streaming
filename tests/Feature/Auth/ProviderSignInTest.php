<?php

namespace Tests\Feature\Auth;

use App\Models\AuthProvider;
use App\Models\Role;
use App\Models\User;
use App\Models\UserIdentity;
use App\Services\Auth\ProviderFactory;
use App\Support\Auth\ConnectionProps;
use App\Support\Auth\ProviderRoles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\User as RemoteUser;
use Mockery;
use Tests\Concerns\ConfiguresAuthProviders;
use Tests\TestCase;

/**
 * Signing in across several providers, and the rule that decides when two of them are
 * the same person: they never are, unless somebody says so from their own settings.
 */
class ProviderSignInTest extends TestCase
{
    use ConfiguresAuthProviders;
    use RefreshDatabase;

    /** The CSRF state the fake driver issues, echoed back on every callback below. */
    private const STATE = 'the-state-we-issued';

    private AuthProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $this->provider = $this->legacyProvider();
    }

    /**
     * Answer the token exchange with a fixed identity. The exchange itself is Guzzle
     * inside Socialite, so the driver is replaced rather than the network.
     */
    private function releases(array $attributes, array $raw = []): void
    {
        $remote = (new RemoteUser)->setRaw($raw)->map($attributes);

        $driver = Mockery::mock(AbstractProvider::class);
        $driver->shouldReceive('user')->andReturn($remote);

        // Socialite writes the CSRF state as it builds the authorize URL, and the flow
        // record is only honoured when what comes back matches it, so the fake has to
        // issue one too.
        $driver->shouldReceive('redirect')->andReturnUsing(function () {
            session(['state' => self::STATE]);

            return redirect('https://identity.example.org/oauth2/auth');
        });

        $factory = Mockery::mock(ProviderFactory::class);
        $factory->shouldReceive('make')->andReturn($driver);

        $this->app->instance(ProviderFactory::class, $factory);
    }

    public function test_an_account_backfilled_from_the_legacy_subject_still_signs_in(): void
    {
        $user = User::factory()->create(['sub' => 'identity-1', 'name' => 'Existing']);
        $this->connect($user, $this->provider, 'identity-1');

        $this->releases(['id' => 'identity-1', 'name' => 'Existing', 'email' => 'existing@example.org']);

        $this->get('/auth/callback?code=x&state='.self::STATE)->assertRedirect();

        $this->assertAuthenticatedAs($user->fresh());
        $this->assertSame(1, User::count());
    }

    public function test_an_unknown_subject_with_an_unknown_address_creates_an_account(): void
    {
        $this->releases(['id' => 'new-subject', 'name' => 'Newcomer', 'email' => 'newcomer@example.org']);

        $this->get('/auth/callback?code=x&state='.self::STATE)->assertRedirect();

        $user = User::where('email', 'newcomer@example.org')->firstOrFail();

        $this->assertAuthenticatedAs($user);
        $this->assertSame('new-subject', $user->identities()->value('subject'));
        // The legacy column is still written while it is there, so a parked ban is
        // still claimed off it.
        $this->assertSame('new-subject', $user->sub);
    }

    /**
     * The whole point of the rule: two strangers must never end up sharing an account
     * because a provider released an address somebody else here already uses.
     */
    public function test_an_address_that_already_belongs_to_an_account_blocks_the_sign_in(): void
    {
        User::factory()->local()->create(['email' => 'shared@example.org']);

        $this->releases(['id' => 'other-subject', 'name' => 'Somebody', 'email' => 'SHARED@example.org']);

        $this->get('/auth/callback?code=x&state='.self::STATE)->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertSame(1, User::count());
        $this->assertDatabaseCount('user_identities', 0);

        $this->assertSame(
            'That address already belongs to an account here. Sign in to it, then add '
                .$this->provider->label.' from your settings.',
            session('errors')->get('oidc')[0],
        );
    }

    /**
     * A response with no address has nothing to collide with.
     */
    public function test_a_provider_that_releases_no_address_still_creates_an_account(): void
    {
        User::factory()->local()->create(['email' => 'shared@example.org']);

        $this->releases(['id' => 'no-email', 'name' => 'Anonymous', 'email' => null]);

        $this->get('/auth/callback?code=x&state='.self::STATE)->assertRedirect();

        $this->assertAuthenticated();
        $this->assertNull(User::latest('id')->first()->email);
    }

    public function test_a_second_provider_is_connected_from_the_settings_page(): void
    {
        $second = AuthProvider::factory()->create(['key' => 'github', 'label' => 'GitHub']);

        $user = User::factory()->create(['sub' => 'identity-1']);
        $this->connect($user, $this->provider, 'identity-1');

        $this->releases(['id' => 'github-1', 'name' => 'Same Person', 'email' => 'same@example.org']);

        $this->actingAs($user)
            ->get(route('settings.connections.connect', $second->key))
            ->assertRedirect(route('auth.provider.redirect', $second->key));

        // Walked rather than skipped: the intent only survives the round trip as a
        // flow record written by the redirect, against the state it issues.
        $this->actingAs($user)->get(route('auth.provider.redirect', $second->key));

        $this->actingAs($user)
            ->get(route('auth.provider.callback', $second->key).'?code=x&state='.self::STATE)
            ->assertRedirect(route('settings.edit', 'connections'));

        $this->assertSame(2, $user->fresh()->identities()->count());
        $this->assertSame(1, User::count());
    }

    /**
     * The same subject cannot be attached twice, and it cannot be moved from one
     * account to another by connecting it again.
     */
    public function test_connecting_a_subject_that_belongs_to_another_account_is_refused(): void
    {
        $second = AuthProvider::factory()->create(['key' => 'github', 'label' => 'GitHub']);

        $owner = User::factory()->create();
        $this->connect($owner, $second, 'github-1');

        $other = User::factory()->create();

        $this->releases(['id' => 'github-1', 'name' => 'Same Person', 'email' => 'same@example.org']);

        $this->actingAs($other)->get(route('settings.connections.connect', $second->key));
        $this->actingAs($other)->get(route('auth.provider.redirect', $second->key));

        $this->actingAs($other)
            ->get(route('auth.provider.callback', $second->key).'?code=x&state='.self::STATE)
            ->assertRedirect(route('settings.edit', 'connections'));

        $this->assertSame(0, $other->fresh()->identities()->count());
        $this->assertSame(
            'That GitHub account is connected to a different account here.',
            session('errors')->get('connection')[0],
        );
    }

    /**
     * Switching every provider off pauses new connections; it does not delete the page
     * people use to see and remove the ones they already hold. It is also where the
     * connect flow lands whatever it decides, so a page that can vanish is a refused
     * connect redirecting to a 404.
     */
    public function test_the_connections_page_survives_the_master_switch_being_off(): void
    {
        config(['auth.modes.oauth2' => false]);

        $stranger = User::factory()->local()->create();

        $this->actingAs($stranger)
            ->get(route('settings.edit', 'connections'))
            ->assertSuccessful();

        $props = ConnectionProps::for($stranger);

        $this->assertCount(1, $props['providers']);
        // Listed, and not connectable while the switch is off.
        $this->assertNull($props['providers'][0]['connectUrl']);
    }

    public function test_a_refused_connect_lands_on_a_page_that_exists(): void
    {
        config(['auth.modes.oauth2' => false]);

        $second = AuthProvider::factory()->create(['key' => 'github', 'label' => 'GitHub']);

        $owner = User::factory()->create();
        $this->connect($owner, $second, 'github-1');

        $other = User::factory()->local()->create();

        $this->releases(['id' => 'github-1', 'name' => 'Same Person', 'email' => 'same@example.org']);

        // Straight at the callback: the switch went off while the round trip was out.
        $this->actingAs($other)
            ->withSession(['auth.flow' => ['intent' => 'connect', 'state' => self::STATE, 'provider' => $second->id]])
            ->get(route('auth.provider.callback', $second->key).'?code=x&state='.self::STATE)
            ->assertRedirect(route('settings.edit', 'connections'));

        $this->actingAs($other)->get(route('settings.edit', 'connections'))->assertSuccessful();
    }

    public function test_the_last_way_into_an_account_cannot_be_disconnected(): void
    {
        $user = User::factory()->create(['sub' => null]);
        $identity = $this->connect($user, $this->provider, 'only-one');

        $this->actingAs($user)
            ->from(route('settings.edit', 'connections'))
            ->delete(route('settings.connections.destroy', $this->provider))
            ->assertSessionHasErrors('connection');

        $this->assertDatabaseHas('user_identities', ['id' => $identity->id]);
        $this->assertSame(
            'That is the only way into this account.',
            session('errors')->get('connection')[0],
        );
    }

    public function test_a_second_way_in_can_be_disconnected(): void
    {
        $second = AuthProvider::factory()->create(['key' => 'github', 'label' => 'GitHub']);

        $user = User::factory()->create(['sub' => null]);
        $this->connect($user, $this->provider, 'one');
        $this->connect($user, $second, 'two');

        $this->actingAs($user)
            ->from(route('settings.edit', 'connections'))
            ->delete(route('settings.connections.destroy', $second))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, $user->fresh()->identities()->count());
    }

    /**
     * A password is the way a one-identity account earns the right to disconnect, and
     * before this it could only be set by an administrator.
     */
    public function test_a_viewer_can_set_a_password_and_then_disconnect(): void
    {
        config(['auth.modes.local' => true]);

        $user = User::factory()->create(['sub' => null]);
        $this->connect($user, $this->provider, 'only-one');

        $this->actingAs($user)->put(route('settings.password.update'), [
            'password' => 'a-long-enough-password',
            'password_confirmation' => 'a-long-enough-password',
        ])->assertSessionHasNoErrors();

        $this->assertNotNull($user->fresh()->password);
        $this->assertSame(2, $user->fresh()->signInMethodCount());

        $this->actingAs($user->fresh())
            ->from(route('settings.edit', 'connections'))
            ->delete(route('settings.connections.destroy', $this->provider))
            ->assertSessionHasNoErrors();

        $this->assertSame(0, $user->fresh()->identities()->count());
    }

    public function test_setting_a_password_needs_the_current_one_when_there_is_one(): void
    {
        config(['auth.modes.local' => true]);

        $user = User::factory()->local('the-old-password')->create();

        $this->actingAs($user)->put(route('settings.password.update'), [
            'password' => 'a-long-enough-password',
            'password_confirmation' => 'a-long-enough-password',
        ])->assertSessionHasErrors('current_password');
    }

    /**
     * Roles follow the provider that granted them, and only that provider.
     */
    public function test_a_sign_in_grants_the_roles_its_own_map_names(): void
    {
        $staff = Role::create(['name' => 'Staff', 'slug' => 'staff', 'external_id' => 'GROUP-STAFF']);
        $baseline = Role::create(['name' => 'Attendee', 'slug' => 'attendee', 'external_id' => Role::BASELINE_EXTERNAL_ID]);

        $this->provider->update([
            'role_map' => [
                ['claim' => 'groups', 'match' => 'exact', 'value' => 'GROUP-STAFF', 'role_id' => $staff->id],
            ],
        ]);

        $this->releases(
            ['id' => 'identity-9', 'name' => 'Crew', 'email' => 'crew@example.org'],
            ['groups' => ['GROUP-STAFF']],
        );

        $this->get('/auth/callback?code=x&state='.self::STATE)->assertRedirect();

        $user = User::where('email', 'crew@example.org')->firstOrFail();

        $this->assertTrue($user->hasRole('staff'));
        $this->assertTrue($user->hasRole('attendee'));
        $this->assertSame(
            [$staff->id, $baseline->id],
            $user->identities()->value('granted_role_ids'),
        );
    }

    /**
     * A package reads like "day-supersponsor-2026", so the longest claim wins or the
     * sponsor rule swallows every supersponsor.
     */
    public function test_the_longest_package_match_wins(): void
    {
        $sponsor = Role::create(['name' => 'Sponsor', 'slug' => 'sponsor', 'external_id' => 'sponsor']);
        $super = Role::create(['name' => 'Supersponsor', 'slug' => 'supersponsor', 'external_id' => 'supersponsor']);

        $this->provider->update([
            'grants_baseline' => false,
            'role_map' => [
                ['claim' => 'packages', 'match' => 'contains', 'value' => 'sponsor', 'role_id' => $sponsor->id],
                ['claim' => 'packages', 'match' => 'contains', 'value' => 'supersponsor', 'role_id' => $super->id],
            ],
        ]);

        $identity = UserIdentity::factory()->create(['auth_provider_id' => $this->provider->id]);

        ProviderRoles::apply($identity, $this->provider->fresh(), [
            'packages' => ['day-supersponsor-2026'],
        ]);

        $user = $identity->user->fresh();

        $this->assertTrue($user->hasRole('supersponsor'));
        $this->assertFalse($user->hasRole('sponsor'));
    }
}
