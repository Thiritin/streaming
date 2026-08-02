<?php

namespace Tests\Feature\Manage;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesManageUsers;
use Tests\TestCase;

class RolesTest extends TestCase
{
    use CreatesManageUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createManageUsers();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Sponsor',
            'slug' => 'sponsor',
            'description' => 'Paid supporter',
            'chat_color' => '#C0C0C0',
            'priority' => 40,
            'is_visible' => true,
            'assigned_at_login' => true,
            'permissions' => [],
        ], $overrides);
    }

    public function test_the_list_loads(): void
    {
        $this->actingAs($this->admin)
            ->get(route('manage.roles.index'))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Manage/Roles/Index')
                ->has('table.rows'));
    }

    public function test_a_role_can_be_created_and_edited(): void
    {
        $this->actingAs($this->admin)
            ->post(route('manage.roles.store'), $this->payload())
            ->assertRedirect();

        $role = Role::where('slug', 'sponsor')->firstOrFail();
        $this->assertSame(40, $role->priority);

        $this->actingAs($this->admin)
            ->put(route('manage.roles.update', $role), $this->payload([
                'permissions' => ['chat.moderate'],
            ]))
            ->assertRedirect();

        $this->assertSame(['chat.moderate'], $role->fresh()->permissions);
    }

    public function test_the_slug_must_be_unique(): void
    {
        Role::create($this->payload());

        $this->actingAs($this->admin)
            ->post(route('manage.roles.store'), $this->payload(['name' => 'Other']))
            ->assertSessionHasErrors('slug');
    }

    public function test_a_role_with_members_cannot_be_deleted(): void
    {
        $role = Role::create($this->payload());
        User::factory()->create()->roles()->attach($role);

        $this->actingAs($this->admin)
            ->delete(route('manage.roles.destroy', $role))
            ->assertRedirect();

        $this->assertDatabaseHas('roles', ['id' => $role->id]);
    }

    public function test_an_empty_role_can_be_deleted(): void
    {
        $role = Role::create($this->payload());

        $this->actingAs($this->admin)
            ->delete(route('manage.roles.destroy', $role))
            ->assertRedirect(route('manage.roles.index'));

        $this->assertDatabaseMissing('roles', ['id' => $role->id]);
    }

    public function test_seeding_defaults_is_refused_once_roles_exist(): void
    {
        // The fixture already created the admin and moderator roles.
        $before = Role::count();

        $this->actingAs($this->admin)
            ->post(route('manage.roles.seed'))
            ->assertRedirect();

        $this->assertSame($before, Role::count());
    }

    public function test_a_moderator_cannot_write_roles(): void
    {
        $this->actingAs($this->moderator)
            ->post(route('manage.roles.store'), $this->payload())
            ->assertForbidden();
    }
}
