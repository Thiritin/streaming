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
            'external_id' => null,
            'description' => 'Paid supporter',
            'chat_color' => '#C0C0C0',
            'priority' => 40,
            'is_visible' => true,
            'external_id' => null,
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

    public function test_two_roles_cannot_claim_the_same_external_id(): void
    {
        Role::create($this->payload(['external_id' => 'GROUP-1']));

        $this->actingAs($this->admin)
            ->post(route('manage.roles.store'), $this->payload([
                'name' => 'Other',
                'slug' => 'other',
                'external_id' => 'GROUP-1',
            ]))
            ->assertSessionHasErrors('external_id');
    }

    public function test_an_empty_external_id_is_stored_as_null_so_many_roles_can_be_manual(): void
    {
        $this->actingAs($this->admin)
            ->post(route('manage.roles.store'), $this->payload(['external_id' => '']))
            ->assertSessionHasNoErrors();

        $this->actingAs($this->admin)
            ->post(route('manage.roles.store'), $this->payload([
                'name' => 'Second',
                'slug' => 'second',
                'external_id' => '',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertNull(Role::where('slug', 'sponsor')->firstOrFail()->external_id);
        $this->assertNull(Role::where('slug', 'second')->firstOrFail()->external_id);
    }

    public function test_saving_returns_to_the_list(): void
    {
        $role = Role::create($this->payload());

        $this->actingAs($this->admin)
            ->put(route('manage.roles.update', $role), $this->payload())
            ->assertRedirect(route('manage.roles.index'));

        $this->actingAs($this->admin)
            ->post(route('manage.roles.store'), $this->payload(['name' => 'Other', 'slug' => 'other']))
            ->assertRedirect(route('manage.roles.index'));
    }

    public function test_login_sync_only_touches_roles_carrying_an_external_id(): void
    {
        $synced = Role::create($this->payload(['external_id' => 'GROUP-STAFF']));
        $manual = Role::create($this->payload([
            'name' => 'Manual',
            'slug' => 'manual',
            'external_id' => null,
        ]));

        $user = User::factory()->create();
        $user->roles()->attach([$synced->id, $manual->id]);

        // Signs in without that group: the synced role goes, the manual one stays.
        $user->syncRolesFromLogin([]);

        $slugs = $user->fresh()->roles->pluck('slug');
        $this->assertFalse($slugs->contains('sponsor'));
        $this->assertTrue($slugs->contains('manual'));

        // Signs in with it again and it comes back.
        $user->syncRolesFromLogin(['GROUP-STAFF']);

        $this->assertTrue($user->fresh()->roles->pluck('slug')->contains('sponsor'));
    }

    public function test_a_moderator_cannot_write_roles(): void
    {
        $this->actingAs($this->moderator)
            ->post(route('manage.roles.store'), $this->payload())
            ->assertForbidden();
    }
}
