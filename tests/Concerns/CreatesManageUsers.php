<?php

namespace Tests\Concerns;

use App\Models\Role;
use App\Models\User;

/**
 * Shared fixtures for the /manage parity suite.
 *
 * Mirrors the setup tests/Feature/Filament/AdminPanelTest.php uses, so both panels are
 * exercised against the same role and permission shape while they run in parallel.
 */
trait CreatesManageUsers
{
    protected User $admin;

    protected User $moderator;

    protected User $viewer;

    protected function createManageUsers(): void
    {
        $adminRole = Role::create([
            'name' => 'Administrator',
            'slug' => 'admin',
            'description' => 'Admin role for testing',
            'permissions' => ['admin.access', 'filament.access', 'stream.manage', 'user.manage', 'chat.moderate'],
            'priority' => 100,
        ]);

        $moderatorRole = Role::create([
            'name' => 'Moderator',
            'slug' => 'moderator',
            'description' => 'Moderator role for testing',
            'permissions' => ['filament.access', 'chat.moderate'],
            'priority' => 90,
        ]);

        $this->admin = User::factory()->create();
        $this->admin->roles()->attach($adminRole);

        $this->moderator = User::factory()->create();
        $this->moderator->roles()->attach($moderatorRole);

        // No roles at all: authenticated, but must not reach the panel.
        $this->viewer = User::factory()->create();
    }
}
