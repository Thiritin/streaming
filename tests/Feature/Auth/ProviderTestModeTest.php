<?php

namespace Tests\Feature\Auth;

use App\Models\AuthProvider;
use App\Models\Role;
use App\Models\User;
use App\Services\Auth\ProviderFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as RemoteUser;
use Mockery;
use RuntimeException;
use Tests\Concerns\ConfiguresAuthProviders;
use Tests\Concerns\CreatesManageUsers;
use Tests\TestCase;

/**
 * Testing a provider by using it: a real round trip to the real provider, with the
 * redirect URI that is actually registered there, that writes nothing at all.
 */
class ProviderTestModeTest extends TestCase
{
    use ConfiguresAuthProviders;
    use CreatesManageUsers;
    use RefreshDatabase;

    private const STATE = 'the-state-we-issued';

    private AuthProvider $provider;

    /** What the faked issuer publishes, and with what status. */
    private array $document = [
        'authorization_endpoint' => 'https://identity.example.org/oauth2/auth',
        'token_endpoint' => 'https://identity.example.org/oauth2/token',
        'userinfo_endpoint' => 'https://identity.example.org/userinfo',
    ];

    private int $documentStatus = 200;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->createManageUsers();

        $this->provider = $this->legacyProvider(['enabled' => false]);

        // One stub, answering from the properties above, because a second Http::fake()
        // inside a test is added behind this one rather than replacing it.
        Http::fake(fn () => Http::response($this->document, $this->documentStatus));
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $raw
     */
    private function releases(array $attributes, array $raw = []): void
    {
        $driver = Mockery::mock(AbstractProvider::class);
        $driver->shouldReceive('user')->andReturn((new RemoteUser)->setRaw($raw)->map($attributes));
        $driver->shouldReceive('redirect')->andReturnUsing(function () {
            session(['state' => self::STATE]);

            return redirect('https://identity.example.org/oauth2/auth');
        });

        $this->fakeFactory($driver);
    }

    private function throwsOnExchange(\Throwable $e): void
    {
        $driver = Mockery::mock(AbstractProvider::class);
        $driver->shouldReceive('user')->andThrow($e);
        $driver->shouldReceive('redirect')->andReturnUsing(function () {
            session(['state' => self::STATE]);

            return redirect('https://identity.example.org/oauth2/auth');
        });

        $this->fakeFactory($driver);
    }

    private function fakeFactory(mixed $driver): void
    {
        $factory = Mockery::mock(ProviderFactory::class);
        $factory->shouldReceive('make')->andReturn($driver);

        $this->app->instance(ProviderFactory::class, $factory);
    }

    /**
     * Start the round trip as the administrator and come back from it.
     *
     * @return array<string, mixed>|null
     */
    private function roundTrip(array $query = ['code' => 'x', 'state' => self::STATE]): ?array
    {
        $this->actingAs($this->admin)->get(route('manage.providers.test', $this->provider));

        $this->actingAs($this->admin)
            ->get('/auth/callback?'.http_build_query($query))
            ->assertRedirect(route('manage.providers.edit', $this->provider));

        return session('provider.test');
    }

    public function test_a_test_reports_what_the_provider_released_and_writes_nothing(): void
    {
        $staff = Role::create(['name' => 'Staff', 'slug' => 'staff', 'external_id' => 'GROUP-STAFF']);

        $this->provider->update([
            'grants_baseline' => false,
            'role_map' => [
                ['claim' => 'groups', 'match' => 'exact', 'value' => 'GROUP-STAFF', 'role_id' => $staff->id],
            ],
        ]);

        $before = User::count();
        $rolesBefore = DB::table('role_user')->count();

        $this->releases(
            ['id' => 'subject-1', 'name' => 'Crew', 'email' => 'crew@example.org'],
            ['groups' => ['GROUP-STAFF'], 'packages' => ['day-sponsor-2026']],
        );

        $report = $this->roundTrip();

        $this->assertTrue($report['ok']);
        $this->assertSame('subject-1', $report['subject']);
        $this->assertSame('crew@example.org', $report['email']);
        $this->assertSame(['Staff'], $report['roles']);
        $this->assertContains(['name' => 'groups', 'value' => 'GROUP-STAFF'], $report['claims']);

        // The whole point: a test is not a sign-in.
        $this->assertSame($before, User::count());
        $this->assertDatabaseCount('user_identities', 0);
        $this->assertSame($rolesBefore, DB::table('role_user')->count());
        $this->assertAuthenticatedAs($this->admin);
    }

    /**
     * Hitting the collision rule is an ordinary outcome of testing with your own
     * address. It is reported, never acted on.
     */
    public function test_an_address_that_already_exists_is_reported_and_not_acted_on(): void
    {
        User::factory()->local()->create(['email' => 'taken@example.org']);

        $this->releases(['id' => 'subject-2', 'name' => 'Somebody', 'email' => 'TAKEN@example.org']);

        $report = $this->roundTrip();

        $this->assertTrue($report['ok']);
        $this->assertStringContainsString('already belongs to an account here', $report['notes'][0]);
        $this->assertDatabaseCount('user_identities', 0);
        $this->assertAuthenticatedAs($this->admin);
    }

    public function test_a_provider_that_releases_no_address_is_reported(): void
    {
        $this->releases(['id' => 'subject-3', 'name' => 'Anonymous', 'email' => null]);

        $report = $this->roundTrip();

        $this->assertTrue($report['ok']);
        $this->assertNull($report['email']);
        $this->assertStringContainsString('released no email address', $report['notes'][0]);
    }

    /**
     * Testing before switching on is the point, so this is the one flow that steps
     * past the usability gate.
     */
    public function test_a_switched_off_provider_can_be_tested(): void
    {
        $this->assertFalse($this->provider->enabled);

        $this->releases(['id' => 'subject-4', 'name' => 'Crew', 'email' => 'crew@example.org']);

        $this->assertTrue($this->roundTrip()['ok']);

        // And is still not a way in for anybody else.
        $this->get(route('auth.provider.redirect', $this->provider->key))->assertNotFound();
    }

    public function test_discovery_that_does_not_resolve_fails_before_leaving(): void
    {
        $this->document = [];
        $this->documentStatus = 404;

        $this->actingAs($this->admin)
            ->from(route('manage.providers.edit', $this->provider))
            ->get(route('manage.providers.test', $this->provider))
            ->assertRedirect(route('manage.providers.edit', $this->provider));

        $report = session('provider.test');

        $this->assertFalse($report['ok']);
        $this->assertStringContainsString('No usable discovery document', $report['reason']);
    }

    public function test_a_rejected_secret_is_named(): void
    {
        $this->throwsOnExchange(new RuntimeException('Client error: 401 invalid_client'));

        $report = $this->roundTrip();

        $this->assertFalse($report['ok']);
        $this->assertSame('The provider rejected the client secret.', $report['reason']);
    }

    public function test_a_refused_callback_url_names_the_url_to_register(): void
    {
        $this->releases(['id' => 'unused']);

        $report = $this->roundTrip(['error' => 'redirect_uri_mismatch', 'state' => self::STATE]);

        $this->assertFalse($report['ok']);
        $this->assertStringContainsString(url('/auth/callback'), $report['reason']);
    }

    public function test_an_expired_test_says_so_rather_than_reading_as_a_provider_fault(): void
    {
        $this->throwsOnExchange(new InvalidStateException);

        $report = $this->roundTrip();

        $this->assertFalse($report['ok']);
        $this->assertStringContainsString('expired', $report['reason']);
    }

    public function test_only_an_administrator_can_start_a_test(): void
    {
        $this->actingAs($this->moderator)
            ->get(route('manage.providers.test', $this->provider))
            ->assertForbidden();
    }

    /**
     * The intent is never in a URL, so a crafted callback cannot claim to be a test.
     * Without the flow record this session wrote, it is an ordinary sign-in.
     */
    public function test_a_callback_cannot_claim_to_be_a_test_on_its_own(): void
    {
        $this->legacyProvider(['enabled' => true]);
        $this->releases(['id' => 'subject-5', 'name' => 'Somebody', 'email' => 'somebody@example.org']);

        $this->withSession(['state' => self::STATE])
            ->get('/auth/callback?'.http_build_query(['code' => 'x', 'state' => self::STATE, 'intent' => 'test']))
            ->assertRedirect();

        $this->assertNull(session('provider.test'));
        $this->assertAuthenticated();
    }

    /**
     * A test that was started and abandoned must not be picked up by an unrelated
     * sign-in later in the same session: the record is bound to the state Socialite
     * issued for that one round trip.
     */
    public function test_an_abandoned_test_does_not_capture_a_later_sign_in(): void
    {
        $this->legacyProvider(['enabled' => true]);
        $this->releases(['id' => 'subject-6', 'name' => 'Somebody', 'email' => 'somebody@example.org']);

        // Pressed Test, never came back.
        $this->actingAs($this->admin)->get(route('manage.providers.test', $this->provider));

        // A different round trip returns, with a state this session did not issue for it.
        $this->actingAs($this->admin)
            ->withSession(['state' => 'a-later-state'])
            ->get('/auth/callback?'.http_build_query(['code' => 'x', 'state' => 'a-later-state']));

        $this->assertNull(session('provider.test'));
    }

    public function test_the_flow_record_only_answers_for_the_provider_it_was_written_for(): void
    {
        $other = AuthProvider::factory()->create(['key' => 'other']);

        $this->releases(['id' => 'subject-7', 'name' => 'Somebody', 'email' => 'somebody@example.org']);

        $this->actingAs($this->admin)->get(route('manage.providers.test', $this->provider));

        $this->actingAs($this->admin)
            ->get(route('auth.provider.callback', $other->key).'?code=x&state='.self::STATE);

        $this->assertNull(session('provider.test'));
    }
}
