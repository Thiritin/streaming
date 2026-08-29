<?php

namespace Tests\Feature\Manage;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesManageUsers;
use Tests\TestCase;

/**
 * Accounts this installation holds itself, made and given a password from /manage.
 *
 * Held to `admin.access` rather than `user.manage`: `user.manage` edits the record of
 * somebody who already has a way in, this hands one out.
 */
class UserAccountsTest extends TestCase
{
    use CreatesManageUsers;
    use RefreshDatabase;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createManageUsers();

        // Past the panel gate and holding user.manage: everything the users module
        // offers except handing out a way in.
        $role = Role::create([
            'name' => 'Operator',
            'slug' => 'operator',
            'permissions' => ['filament.access', 'stream.manage', 'user.manage'],
            'priority' => 50,
        ]);

        $this->operator = User::factory()->create();
        $this->operator->roles()->attach($role);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Booth Operator',
            'email' => 'booth@example.org',
            'password' => 'a-long-enough-password',
            'password_confirmation' => 'a-long-enough-password',
            'roles' => [],
        ], $overrides);
    }

    public function test_the_create_screen_opens_on_an_empty_form(): void
    {
        $this->actingAs($this->admin)
            ->get(route('manage.users.create'))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Manage/Users/Form')
                ->where('user', null)
                ->has('options.roles'));
    }

    public function test_creating_an_account_holds_a_password_and_no_subject(): void
    {
        Role::create([
            'name' => 'Attendee',
            'slug' => 'attendee',
            'external_id' => Role::BASELINE_EXTERNAL_ID,
            'permissions' => [],
        ]);

        $this->actingAs($this->admin)
            ->post(route('manage.users.store'), $this->payload(['roles' => ['moderator']]))
            ->assertRedirect();

        $user = User::where('email', 'booth@example.org')->firstOrFail();

        $this->assertNull($user->sub);
        $this->assertTrue(Hash::check('a-long-enough-password', $user->password));
        // Typed in by an administrator, so there is nothing to prove by mail.
        $this->assertNotNull($user->email_verified_at);
        // The baseline role every account gets, plus what was ticked on the form.
        $this->assertTrue($user->hasRole('attendee'));
        $this->assertTrue($user->hasRole('moderator'));
    }

    public function test_creating_refuses_an_address_another_local_account_holds(): void
    {
        User::factory()->local()->create(['email' => 'booth@example.org']);

        $this->actingAs($this->admin)
            ->from(route('manage.users.create'))
            ->post(route('manage.users.store'), $this->payload())
            ->assertSessionHasErrors('email');

        $this->assertSame(1, User::where('email', 'booth@example.org')->count());
    }

    /**
     * The provider rewrites `users.email` from its claim at every sign-in, so an
     * address it already uses is not taken as far as this form is concerned.
     */
    public function test_an_address_the_provider_uses_does_not_block_a_local_account(): void
    {
        User::factory()->create(['email' => 'booth@example.org']);

        $this->actingAs($this->admin)
            ->post(route('manage.users.store'), $this->payload())
            ->assertSessionHasNoErrors();

        $this->assertSame(1, User::where('email', 'booth@example.org')->whereNotNull('password')->count());
    }

    public function test_managing_users_is_not_enough_to_create_an_account(): void
    {
        $this->actingAs($this->operator)->get(route('manage.users.create'))->assertForbidden();

        $this->actingAs($this->operator)
            ->post(route('manage.users.store'), $this->payload())
            ->assertForbidden();

        $this->assertNull(User::where('email', 'booth@example.org')->first());
    }

    public function test_a_password_is_set_on_an_existing_account(): void
    {
        $user = User::factory()->create();

        $this->actingAs($this->admin)
            ->put(route('manage.users.password.update', $user), [
                'password' => 'a-long-enough-password',
                'password_confirmation' => 'a-long-enough-password',
            ])
            ->assertRedirect();

        $this->assertTrue(Hash::check('a-long-enough-password', $user->fresh()->password));
    }

    public function test_a_password_that_is_not_confirmed_is_refused(): void
    {
        $user = User::factory()->create();

        $this->actingAs($this->admin)
            ->from(route('manage.users.edit', $user))
            ->put(route('manage.users.password.update', $user), [
                'password' => 'a-long-enough-password',
                'password_confirmation' => 'something-else',
            ])
            ->assertSessionHasErrors('password');

        $this->assertNull($user->fresh()->password);
    }

    public function test_managing_users_is_not_enough_to_set_a_password(): void
    {
        $user = User::factory()->create();

        $this->actingAs($this->operator)
            ->put(route('manage.users.password.update', $user), [
                'password' => 'a-long-enough-password',
                'password_confirmation' => 'a-long-enough-password',
            ])
            ->assertForbidden();

        $this->assertNull($user->fresh()->password);
    }

    /**
     * Clearing leaves the identity provider as the way in, so the account has to have
     * one behind it.
     */
    public function test_a_password_is_cleared_from_an_account_the_provider_owns(): void
    {
        $user = User::factory()->create(['password' => 'a-long-enough-password']);

        $this->actingAs($this->admin)
            ->delete(route('manage.users.password.destroy', $user))
            ->assertRedirect();

        $this->assertNull($user->fresh()->password);
    }

    public function test_the_last_way_into_a_local_account_is_not_cleared(): void
    {
        $user = User::factory()->local('a-long-enough-password')->create();

        $this->actingAs($this->admin)
            ->from(route('manage.users.edit', $user))
            ->delete(route('manage.users.password.destroy', $user))
            ->assertRedirect();

        $this->assertNotNull($user->fresh()->password);
    }

    public function test_the_edit_screen_reports_whether_a_password_is_held(): void
    {
        $user = User::factory()->local()->create();

        $this->actingAs($this->admin)
            ->get(route('manage.users.edit', $user))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Manage/Users/Form')
                ->where('user.has_password', true)
                ->where('can.password', true));
    }

    public function test_the_password_controls_are_absent_for_an_operator(): void
    {
        $user = User::factory()->local()->create();

        $this->actingAs($this->operator)
            ->get(route('manage.users.edit', $user))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page->where('can.password', false));
    }
}
