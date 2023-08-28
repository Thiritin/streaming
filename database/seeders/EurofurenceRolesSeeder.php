<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class EurofurenceRolesSeeder extends Seeder
{
    public function run(): void
    {
        // Permissions
        $this->permissions();
        $attendee = Role::updateOrCreate(['name' => 'Attendee'],[
            'color' => null,
            "priority" => 10,
        ]);
        $attendee->givePermissionTo('stream.view');

        $sponsor = Role::updateOrCreate(['name' => 'Sponsor'],[
            'color' => 'text-yellow-500',
            "priority" => 20,
        ]);
        $sponsor->givePermissionTo('stream.view');

        $superSponsor = Role::updateOrCreate(['name' => 'Super Sponsor'],[
            'color' => 'text-purple-500',
            "priority" => 30,
        ]);
        $superSponsor->givePermissionTo('stream.view');

        // Moderator Roles
        $moderator = Role::updateOrCreate(['name' => 'Moderator'],[
            'color' => 'text-orange-500',
            "priority" => 800,
        ]);
        $moderator->givePermissionTo([
            'stream.view',
            "chat.ignore.ratelimit",
            'chat.commands.broadcast',
            'chat.commands.delete',
            'chat.commands.slowmode',
            'chat.commands.timeout',
        ]);

        // Admin Roles
        $admin = Role::updateOrCreate(['name' => 'Admin'],[
            'color' => 'text-red-500',
            "priority" => 1000,
        ]);
        $admin->givePermissionTo([
            'stream.view',
            'filament.access',
            "chat.ignore.ratelimit",
            'chat.commands.broadcast',
            'chat.commands.nukeall',
            'chat.commands.delete',
            'chat.commands.slowmode',
            'chat.commands.timeout',
        ]);
    }

    private function permissions()
    {
        collect([
            "stream.view",
            "filament.access",
            "chat.ignore.ratelimit",
            "chat.commands.broadcast",
            'chat.commands.nukeall',
            "chat.commands.delete",
            "chat.commands.slowmode",
            "chat.commands.timeout",
        ])->map(fn($perm) => [
            'name' => $perm,
        ])->each(fn($perm) => Permission::firstOrCreate($perm));
    }
}
