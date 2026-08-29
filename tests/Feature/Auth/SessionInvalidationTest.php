<?php

namespace Tests\Feature\Auth;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * What AuthenticateSession does to this installation, now that it is in the web group.
 *
 * It is there so a password change ends the account's other sessions, which a reset
 * otherwise does not. It runs on every request by every signed-in person, so the blast
 * radius is worth pinning down rather than reasoning about: an account the identity
 * provider owns has no password to compare, and a remembered account must survive its
 * next visit.
 */
class SessionInvalidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        config([
            'auth.modes.local' => true,
            'auth.modes.oidc' => false,
            'services.oidc.url' => null,
            'services.oidc.client_id' => null,
        ]);
    }

    /**
     * The early return at AuthenticateSession:47, before anything is read or written.
     */
    public function test_an_identity_provider_account_is_left_entirely_alone(): void
    {
        $user = User::factory()->create();

        $this->assertNull($user->password);

        $this->actingAs($user)->get('/settings')->assertOk();

        $this->assertAuthenticatedAs($user);
        // Nothing was written to the session on its behalf.
        $this->assertFalse(session()->has('password_hash_web'));
    }

    /**
     * The whole point of it: whoever knew the old password is turned away.
     */
    public function test_a_password_change_ends_the_accounts_other_sessions(): void
    {
        $user = User::factory()->local('the-old-password')->create();

        $user->forceFill(['password' => 'a-brand-new-password'])->save();

        $response = $this->withSession(['password_hash_web' => Hash::make('the-old-password')])
            ->actingAs($user->fresh())
            ->get('/settings');

        $this->assertNotSame(200, $response->getStatusCode());
        $this->assertGuest();
    }

    /**
     * An administrator setting somebody's password from /manage is the same event, so
     * the sessions that account already had open end too. That is what we want: the
     * usual reason to set it for them is that they have lost control of it.
     */
    public function test_an_administrator_setting_a_password_ends_that_accounts_sessions(): void
    {
        $role = Role::create([
            'name' => 'Administrator',
            'slug' => 'admin',
            'permissions' => ['admin.access'],
            'priority' => 100,
        ]);

        $admin = User::factory()->local('the-admins-own-password')->create();
        $admin->roles()->attach($role);

        $subject = User::factory()->local('the-old-password')->create();

        $this->actingAs($admin)
            ->from(route('manage.users.edit', $subject))
            ->put(route('manage.users.password.update', $subject), [
                'password' => 'a-password-set-for-them',
                'password_confirmation' => 'a-password-set-for-them',
            ])->assertRedirect();

        // The administrator's own session survives their own request.
        $this->assertAuthenticatedAs($admin);

        $response = $this->withSession(['password_hash_web' => Hash::make('the-old-password')])
            ->actingAs($subject->fresh())
            ->get('/settings');

        $this->assertNotSame(200, $response->getStatusCode());
        $this->assertGuest();
    }

    /**
     * An administrator changing their own password keeps the session they did it from:
     * the middleware re-stamps the hash on the way out of the same request.
     */
    public function test_changing_your_own_password_does_not_sign_you_out(): void
    {
        $role = Role::create([
            'name' => 'Administrator',
            'slug' => 'admin',
            'permissions' => ['admin.access'],
            'priority' => 100,
        ]);

        $admin = User::factory()->local('the-old-password')->create();
        $admin->roles()->attach($role);

        $this->actingAs($admin);
        $this->get('/settings')->assertOk();

        $this->put(route('manage.users.password.update', $admin), [
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ])->assertRedirect();

        $this->get('/settings')->assertOk();
        $this->assertAuthenticatedAs($admin);
    }

    /**
     * This installation signs people in for four weeks and expects them to stay signed
     * in for the run-up and the convention, so a remembered account being logged out on
     * its next visit would be the worst possible regression here.
     */
    public function test_a_remembered_local_account_survives_its_next_visit(): void
    {
        $user = User::factory()->local('a-long-enough-password')->create();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'a-long-enough-password',
            'remember' => true,
        ])->assertRedirect('/');

        /*
         * A new visit carrying only the remember cookie, which is what coming back days
         * later looks like. Not Auth::logout() to clear the session - that cycles the
         * remember token and would invalidate the very cookie under test.
         */
        $this->flushSession();
        $this->app['auth']->forgetGuards();

        $this->withCookie(...$this->recaller($user->fresh()))
            ->get('/settings')
            ->assertOk();

        $this->assertTrue(Auth::guard('web')->viaRemember());
        $this->assertAuthenticatedAs($user);
    }

    /**
     * And the other half of the same guard: a remembered session whose password has
     * since changed is turned away rather than waved through.
     */
    public function test_a_remembered_session_dies_when_the_password_changes(): void
    {
        $user = User::factory()->local('a-long-enough-password')->create();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'a-long-enough-password',
            'remember' => true,
        ])->assertRedirect('/');

        $cookie = $this->recaller($user->fresh());

        $user->forceFill(['password' => 'a-brand-new-password'])->save();

        $this->flushSession();
        $this->app['auth']->forgetGuards();

        $this->assertNotSame(
            200,
            $this->withCookie(...$cookie)->get('/settings')->getStatusCode(),
        );
    }

    /**
     * The remember cookie as the guard writes it: id, token and password hash. Built
     * rather than read off the response, because the test client encrypts whatever
     * withCookie() is given and handing back an already-encrypted value encrypts it
     * twice, which decrypts to nothing and looks exactly like a rejected session.
     *
     * @return array{0: string, 1: string}
     */
    private function recaller(User $user): array
    {
        return [
            Auth::guard('web')->getRecallerName(),
            $user->id.'|'.$user->remember_token.'|'.$user->password,
        ];
    }
}
