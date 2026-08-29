<?php

namespace Tests\Feature\Auth;

use App\Models\Role;
use App\Models\User;
use App\Notifications\ResetPassword;
use App\Notifications\VerifyEmail;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Signing in, registering and resetting a password on an installation that holds
 * accounts of its own.
 */
class PasswordAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        config([
            'auth.modes.local' => true,
            'auth.modes.registration' => false,
            'auth.modes.oidc' => false,
            'services.oidc.url' => null,
            'services.oidc.client_id' => null,
        ]);
    }

    public function test_a_local_account_signs_in_with_its_password(): void
    {
        $user = User::factory()->local('a-long-enough-password')->create();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'a-long-enough-password',
        ])->assertRedirect('/');

        $this->assertAuthenticatedAs($user);
    }

    public function test_the_wrong_password_is_refused(): void
    {
        $user = User::factory()->local()->create();

        $this->from('/login')->post('/login', [
            'email' => $user->email,
            'password' => 'not-it',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    /**
     * `users.email` is nullable and not unique, and the identity provider rewrites it
     * from its claim on every sign-in, so the credential lookup has to exclude every
     * account that holds no password of its own.
     */
    public function test_an_identity_provider_account_cannot_be_signed_in_with_a_password(): void
    {
        User::factory()->create(['email' => 'shared@example.org']);

        $this->from('/login')->post('/login', [
            'email' => 'shared@example.org',
            'password' => 'a-long-enough-password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    /**
     * Two accounts on one address, one from the provider and one local: the local one
     * is the only one a password can reach, whichever was written first.
     */
    public function test_a_shared_address_still_reaches_the_local_account(): void
    {
        User::factory()->create(['email' => 'shared@example.org']);
        $local = User::factory()->local('a-long-enough-password')->create(['email' => 'shared@example.org']);

        $this->post('/login', [
            'email' => 'shared@example.org',
            'password' => 'a-long-enough-password',
        ])->assertRedirect('/');

        $this->assertAuthenticatedAs($local);
    }

    /**
     * Not Session::flush(), which takes the CSRF token with it: a signed-in viewer
     * whose next request is a POST would get a 419 that looks like nothing to do
     * with signing in.
     */
    public function test_signing_in_keeps_a_usable_session(): void
    {
        $user = User::factory()->local('a-long-enough-password')->create();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'a-long-enough-password',
        ]);

        $this->assertNotNull(session()->token());
    }

    public function test_signing_out(): void
    {
        $user = User::factory()->local()->create();

        $this->actingAs($user)->post('/logout')->assertRedirect('/');

        $this->assertGuest();
    }

    /**
     * Ending only the local session would leave the provider believing the person is
     * still signed in, and its next authorize round would wave them straight back
     * through without asking for anything.
     */
    public function test_an_identity_provider_session_signs_out_through_the_provider(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/logout')
            ->assertRedirect(route('auth.frontchannel-logout'));
    }

    /**
     * A reset has to turn away whoever knew the old password, not just the browser
     * asking for a new one.
     */
    public function test_a_reset_ends_the_accounts_other_sessions(): void
    {
        Notification::fake();

        $user = User::factory()->local('the-old-password')->create();
        $before = $user->remember_token;

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
            $this->post('/reset-password', [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'a-brand-new-password',
                'password_confirmation' => 'a-brand-new-password',
            ])->assertRedirect(route('login'));

            return true;
        });

        // Remembered sessions elsewhere die with the token.
        $this->assertNotSame($before, $user->fresh()->remember_token);

        /*
         * And a session already open elsewhere is turned away on its next request:
         * it carries the hash the account was signed in under, which no longer
         * matches. AuthenticateSession in the web group is what enforces it.
         */
        $response = $this->withSession(['password_hash_web' => Hash::make('the-old-password')])
            ->actingAs($user->fresh())
            ->get('/settings');

        $this->assertNotSame(200, $response->getStatusCode());
        $this->assertGuest();
    }

    public function test_registration_is_closed_unless_it_is_switched_on(): void
    {
        $this->get('/register')->assertNotFound();

        $this->post('/register', [
            'name' => 'Someone',
            'email' => 'someone@example.org',
            'password' => 'a-long-enough-password',
            'password_confirmation' => 'a-long-enough-password',
        ])->assertNotFound();

        $this->assertNull(User::where('email', 'someone@example.org')->first());
    }

    public function test_registering_creates_a_local_account_without_the_baseline_role(): void
    {
        config(['auth.modes.registration' => true]);
        Notification::fake();

        $this->baselineRole();

        $this->post('/register', [
            'name' => 'Someone',
            'email' => 'someone@example.org',
            'password' => 'a-long-enough-password',
            'password_confirmation' => 'a-long-enough-password',
        ])->assertRedirect('/');

        $user = User::where('email', 'someone@example.org')->firstOrFail();

        $this->assertNull($user->sub);
        $this->assertTrue(Hash::check('a-long-enough-password', $user->password));
        $this->assertNull($user->email_verified_at);
        $this->assertFalse($user->hasRole('attendee'));
        $this->assertAuthenticatedAs($user);

        Notification::assertSentTo($user, VerifyEmail::class);
    }

    /**
     * A form open to the internet is not evidence that the address belongs to whoever
     * filled it in, so the role that makes an account an attendee waits for the link.
     */
    public function test_confirming_the_address_hands_over_the_baseline_role(): void
    {
        config(['auth.modes.registration' => true]);

        $this->baselineRole();

        $user = User::factory()->local()->create(['email_verified_at' => null]);

        $this->assertFalse($user->hasRole('attendee'));

        $this->actingAs($user)->get(URL::temporarySignedRoute('verification.verify', now()->addHour(), [
            'id' => $user->id,
            'hash' => sha1($user->email),
        ]))->assertRedirect('/?verified=1');

        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertTrue($user->fresh()->hasRole('attendee'));
    }

    /**
     * An installation with no mail cannot send the link, so an administrator makes the
     * same decision from the panel.
     */
    public function test_an_administrator_can_confirm_an_address_from_the_panel(): void
    {
        $this->baselineRole();

        $role = Role::create([
            'name' => 'Administrator',
            'slug' => 'admin',
            'permissions' => ['admin.access'],
            'priority' => 100,
        ]);

        $admin = User::factory()->create();
        $admin->roles()->attach($role);

        $user = User::factory()->local()->create(['email_verified_at' => null]);

        $this->actingAs($admin)
            ->from(route('manage.users.edit', $user))
            ->post(route('manage.users.verify', $user))
            ->assertRedirect(route('manage.users.edit', $user));

        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertTrue($user->fresh()->hasRole('attendee'));
    }

    public function test_confirming_an_address_from_the_panel_needs_admin_access(): void
    {
        $user = User::factory()->local()->create(['email_verified_at' => null]);

        $this->actingAs(User::factory()->create())
            ->post(route('manage.users.verify', $user))
            ->assertForbidden();

        $this->assertNull($user->fresh()->email_verified_at);
    }

    /**
     * The token row is written before the message is built, so an installation whose
     * mail is down must not answer 500 on a request that half succeeded.
     */
    public function test_the_reset_mail_is_queued(): void
    {
        $this->assertInstanceOf(ShouldQueue::class, new ResetPassword('token'));
        $this->assertInstanceOf(ShouldQueue::class, new VerifyEmail);
    }

    private function baselineRole(): Role
    {
        return Role::create([
            'name' => 'Attendee',
            'slug' => 'attendee',
            'external_id' => Role::BASELINE_EXTERNAL_ID,
            'permissions' => [],
        ]);
    }

    public function test_registering_refuses_an_address_another_local_account_holds(): void
    {
        config(['auth.modes.registration' => true]);

        User::factory()->local()->create(['email' => 'taken@example.org']);

        $this->from('/register')->post('/register', [
            'name' => 'Someone',
            'email' => 'taken@example.org',
            'password' => 'a-long-enough-password',
            'password_confirmation' => 'a-long-enough-password',
        ])->assertSessionHasErrors('email');

        $this->assertSame(1, User::where('email', 'taken@example.org')->count());
    }

    public function test_a_reset_link_is_sent_to_a_local_account(): void
    {
        Notification::fake();

        $user = User::factory()->local()->create();

        $this->post('/forgot-password', ['email' => $user->email])
            ->assertSessionHasNoErrors();

        Notification::assertSentTo($user, ResetPassword::class);
    }

    /**
     * An account the identity provider owns has its password there. A link that set
     * one here would quietly give it a second way in.
     */
    public function test_no_reset_link_is_sent_to_an_identity_provider_account(): void
    {
        Notification::fake();

        $user = User::factory()->create(['email' => 'claimed@example.org']);

        $this->from('/forgot-password')->post('/forgot-password', ['email' => 'claimed@example.org'])
            ->assertSessionHasNoErrors();

        Notification::assertNothingSentTo($user);
    }

    /**
     * An address nobody holds answers exactly as one that does. Telling the two apart
     * is telling a stranger which addresses have an account here.
     */
    public function test_an_unknown_address_answers_the_same_as_a_known_one(): void
    {
        Notification::fake();

        $known = User::factory()->local()->create();

        $held = $this->post('/forgot-password', ['email' => $known->email]);
        $unheld = $this->post('/forgot-password', ['email' => 'nobody@example.org']);

        $held->assertSessionHasNoErrors();
        $unheld->assertSessionHasNoErrors();
        $this->assertSame(
            $held->getSession()->get('status'),
            $unheld->getSession()->get('status'),
        );
    }

    public function test_a_reset_link_sets_a_new_password(): void
    {
        Notification::fake();

        $user = User::factory()->local()->create();

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
            $this->post('/reset-password', [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'a-brand-new-password',
                'password_confirmation' => 'a-brand-new-password',
            ])->assertRedirect(route('login'));

            return true;
        });

        $this->assertTrue(Hash::check('a-brand-new-password', $user->fresh()->password));

        $this->assertTrue(Auth::attempt([
            'email' => $user->email,
            'password' => 'a-brand-new-password',
        ]));
    }

    /**
     * A convention venue leaves through one NAT, so a limit keyed by address alone
     * would be a handful of sign-ins a minute for the whole building.
     */
    public function test_one_address_does_not_use_up_the_venues_sign_in_attempts(): void
    {
        for ($i = 1; $i <= 12; $i++) {
            $response = $this->from('/login')->post('/login', [
                'email' => "viewer{$i}@example.org",
                'password' => 'wrong-password',
            ]);

            $this->assertNotSame(429, $response->getStatusCode(), "attempt {$i} was throttled");
        }
    }

    public function test_a_throttled_sign_in_lands_on_the_sites_own_error_page(): void
    {
        RateLimiter::for('auth', fn () => Limit::perMinute(1)->by('one-key-for-this-test'));

        $this->post('/login', ['email' => 'viewer@example.org', 'password' => 'wrong-password']);

        $response = $this->post('/login', ['email' => 'viewer@example.org', 'password' => 'wrong-password']);

        $response->assertStatus(429);
        $response->assertInertia(fn (AssertableInertia $page) => $page->component('ErrorPage'));
    }

    public function test_the_reset_pages_close_with_the_mode(): void
    {
        config(['auth.modes.local' => false, 'auth.modes.oidc' => true, 'services.oidc.url' => 'https://identity.example.org', 'services.oidc.client_id' => 'streaming']);

        $this->get('/forgot-password')->assertNotFound();
        $this->post('/forgot-password', ['email' => 'someone@example.org'])->assertNotFound();
        $this->get('/reset-password/whatever')->assertNotFound();
        $this->post('/reset-password', [])->assertNotFound();
    }
}
