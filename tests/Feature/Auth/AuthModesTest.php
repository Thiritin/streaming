<?php

namespace Tests\Feature\Auth;

use App\Models\AuthProvider;
use App\Support\AuthModes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\ConfiguresAuthProviders;
use Tests\TestCase;

/**
 * The three ways in, in every combination, plus the safeguards that stop an
 * administrator switching the last one off from the settings pane.
 */
class AuthModesTest extends TestCase
{
    use ConfiguresAuthProviders;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The register and reset pages are not built yet, and the root view asks Vite
        // for the chunk of whatever page it is rendering.
        $this->withoutVite();
    }

    private function modes(bool $oidc = false, bool $local = false, bool $registration = false, bool $guests = false): void
    {
        config([
            'auth.modes.local' => $local,
            'auth.modes.registration' => $registration,
            'auth.required' => ! $guests,
        ]);

        // The provider is a row, so switching it on is switching a row on.
        $this->legacyProvider(['enabled' => $oidc]);
    }

    /**
     * The screen is the one page that renders whatever is on, so it is what the
     * combinations are read off.
     *
     * @return array{oidc: bool, local: bool, registration: bool, guest: bool}
     */
    private function offered(): array
    {
        $response = $this->get('/login');
        $response->assertOk();

        $modes = null;
        $response->assertInertia(function (AssertableInertia $page) use (&$modes) {
            $modes = $page->toArray()['props']['modes'];
        });

        // The four flags the screen lays itself out from. `providers` is the list it
        // draws one button per and has a test of its own.
        return Arr::only($modes, ['oidc', 'local', 'registration', 'guest']);
    }

    public function test_the_identity_provider_alone(): void
    {
        $this->modes(oidc: true);

        $this->assertSame(
            ['oidc' => true, 'local' => false, 'registration' => false, 'guest' => false],
            $this->offered(),
        );

        $this->post('/login', ['email' => 'someone@example.org', 'password' => 'password'])
            ->assertNotFound();
        $this->get('/register')->assertNotFound();
        $this->get('/forgot-password')->assertNotFound();
    }

    public function test_password_accounts_alone(): void
    {
        $this->modes(local: true);

        $this->assertSame(
            ['oidc' => false, 'local' => true, 'registration' => false, 'guest' => false],
            $this->offered(),
        );

        $this->get('/forgot-password')->assertOk();
        // Registration is an option on password accounts, not implied by them.
        $this->get('/register')->assertNotFound();
    }

    public function test_guest_access_alone_offers_no_way_to_sign_in(): void
    {
        $this->modes(guests: true);

        $this->assertSame(
            ['oidc' => false, 'local' => false, 'registration' => false, 'guest' => true],
            $this->offered(),
        );

        $this->post('/login', ['email' => 'someone@example.org', 'password' => 'password'])
            ->assertNotFound();
    }

    public function test_no_mode_at_all_still_renders_the_screen(): void
    {
        $this->modes();

        $this->assertSame(
            ['oidc' => false, 'local' => false, 'registration' => false, 'guest' => false],
            $this->offered(),
        );

        $this->assertFalse(AuthModes::any());
    }

    public function test_password_accounts_and_guest_access(): void
    {
        $this->modes(local: true, guests: true);

        $this->assertSame(
            ['oidc' => false, 'local' => true, 'registration' => false, 'guest' => true],
            $this->offered(),
        );
    }

    public function test_the_identity_provider_and_guest_access(): void
    {
        $this->modes(oidc: true, guests: true);

        $this->assertSame(
            ['oidc' => true, 'local' => false, 'registration' => false, 'guest' => true],
            $this->offered(),
        );
    }

    public function test_all_three_at_once(): void
    {
        $this->modes(oidc: true, local: true, registration: true, guests: true);

        $this->assertSame(
            ['oidc' => true, 'local' => true, 'registration' => true, 'guest' => true],
            $this->offered(),
        );

        $this->get('/register')->assertOk();
    }

    /**
     * A switch with no endpoint behind it is a button that fails on the second page,
     * so it is not offered whatever the row says.
     */
    public function test_the_provider_is_not_offered_without_an_endpoint(): void
    {
        $this->modes(local: true);
        $this->legacyProvider(['enabled' => true, 'issuer_url' => null, 'client_id' => null]);

        $this->assertFalse(AuthModes::oidc());
        $this->assertFalse($this->offered()['oidc']);
    }

    /**
     * One button per enabled row, in order, which is the whole point of the table.
     */
    public function test_every_enabled_provider_is_offered_as_its_own_button(): void
    {
        $this->modes(oidc: true);

        AuthProvider::factory()->create([
            'driver' => 'github',
            'key' => 'github',
            'label' => 'GitHub',
            'order' => 5,
        ]);

        $response = $this->get('/login');

        $providers = null;
        $response->assertInertia(function (AssertableInertia $page) use (&$providers) {
            $providers = $page->toArray()['props']['modes']['providers'];
        });

        $this->assertSame(['identity', 'github'], array_column($providers, 'key'));
        $this->assertSame(route('auth.provider.redirect', 'github'), $providers[1]['url']);
    }

    public function test_registration_needs_password_accounts(): void
    {
        $this->modes(oidc: true, registration: true);

        $this->assertFalse(AuthModes::registration());
        $this->get('/register')->assertNotFound();
    }
}
