<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * What the sign-in screen offers, permutation by permutation.
 *
 * The page renders itself from `modes`, so the combination in the props is the
 * combination on the screen: the password form, the divider, the provider button,
 * the registration link and the guest link each hang off one of these four.
 */
class LoginScreenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    private function modes(bool $oidc = false, bool $local = false, bool $registration = false, bool $guests = false): void
    {
        config([
            'auth.modes.oidc' => $oidc,
            'auth.modes.local' => $local,
            'auth.modes.registration' => $registration,
            'auth.required' => ! $guests,
            'services.oidc.url' => $oidc ? 'https://identity.example.org' : null,
            'services.oidc.client_id' => $oidc ? 'streaming' : null,
        ]);
    }

    private function assertScreen(array $expected): void
    {
        $this->get(route('login'))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Auth/Login')
                ->has('schedule')
                ->where('modes', $expected));
    }

    public function test_password_accounts_alone(): void
    {
        $this->modes(local: true);

        $this->assertScreen(['oidc' => false, 'local' => true, 'registration' => false, 'guest' => false]);
    }

    public function test_the_identity_provider_alone(): void
    {
        $this->modes(oidc: true);

        $this->assertScreen(['oidc' => true, 'local' => false, 'registration' => false, 'guest' => false]);
    }

    public function test_guest_access_alone(): void
    {
        $this->modes(guests: true);

        $this->assertScreen(['oidc' => false, 'local' => false, 'registration' => false, 'guest' => true]);
    }

    /**
     * Nothing on: the screen still answers, and there is no button to press.
     */
    public function test_no_mode_at_all(): void
    {
        $this->modes();

        $this->assertScreen(['oidc' => false, 'local' => false, 'registration' => false, 'guest' => false]);
    }

    /**
     * Both, which is the only combination that renders the divider and relabels the
     * provider button.
     */
    public function test_password_accounts_and_the_identity_provider(): void
    {
        $this->modes(oidc: true, local: true);

        $this->assertScreen(['oidc' => true, 'local' => true, 'registration' => false, 'guest' => false]);
    }

    public function test_all_three_at_once(): void
    {
        $this->modes(oidc: true, local: true, registration: true, guests: true);

        $this->assertScreen(['oidc' => true, 'local' => true, 'registration' => true, 'guest' => true]);
    }

    /**
     * The registration link is the only thing `registration` drives, and it needs
     * password accounts under it: there is nowhere else to create one.
     */
    public function test_registration_is_only_offered_with_password_accounts(): void
    {
        $this->modes(local: true, registration: true);

        $this->assertScreen(['oidc' => false, 'local' => true, 'registration' => true, 'guest' => false]);

        $this->modes(oidc: true, registration: true);

        $this->assertScreen(['oidc' => true, 'local' => false, 'registration' => false, 'guest' => false]);
    }

    /**
     * The reset link comes back to this page with a line to render.
     */
    public function test_a_reset_confirmation_is_handed_to_the_page(): void
    {
        $this->modes(local: true);

        $this->withSession(['status' => 'We have emailed your password reset link.'])
            ->get(route('login'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('status', 'We have emailed your password reset link.'));
    }
}
