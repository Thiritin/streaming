<?php

namespace Tests\Feature\Manage;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesManageUsers;
use Tests\TestCase;

class UsersTest extends TestCase
{
    use CreatesManageUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createManageUsers();
    }

    public function test_the_list_loads_with_the_declared_columns(): void
    {
        $this->actingAs($this->admin)
            ->get(route('manage.users.index'))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Manage/Users/Index')
                ->has('table.columns')
                ->has('table.rows'));
    }

    public function test_the_detail_page_exposes_the_identity_fields_read_only(): void
    {
        $this->actingAs($this->admin)
            ->get(route('manage.users.edit', $this->viewer))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Manage/Users/Form')
                ->where('user.id', $this->viewer->id)
                ->has('options.roles'));
    }

    public function test_roles_are_synced_by_slug(): void
    {
        $role = Role::create([
            'name' => 'Sponsor',
            'slug' => 'sponsor',
            'priority' => 40,
            'permissions' => [],
        ]);

        $this->actingAs($this->admin)
            ->put(route('manage.users.update', $this->viewer), [
                'roles' => ['sponsor'],
            ])
            ->assertRedirect();

        $this->assertTrue($this->viewer->fresh()->roles->contains($role));
    }

    public function test_deleting_your_own_account_is_refused(): void
    {
        $this->actingAs($this->admin)
            ->delete(route('manage.users.destroy', $this->admin))
            ->assertRedirect();

        $this->assertDatabaseHas('users', ['id' => $this->admin->id]);
    }

    public function test_a_user_without_manage_permission_cannot_edit(): void
    {
        $subject = User::factory()->create();

        $this->actingAs($this->moderator)
            ->put(route('manage.users.update', $subject), [
                'roles' => [],
            ])
            ->assertForbidden();
    }
}
