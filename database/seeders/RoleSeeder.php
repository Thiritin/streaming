<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Admin role with full permissions
        Role::updateOrCreate(
            ['slug' => 'admin'],
            [
                'name' => 'Administrator',
                'description' => 'Full system administrator with all permissions',
                'chat_color' => '#FF0000', // Red color for admins
                'priority' => 100, // Highest priority
                'external_id' => null, // Set the provider's group ID to sync this role at login
                'is_visible' => true,
                'permissions' => [
                    'admin.access',
                    'filament.access',
                    'chat.moderate',
                    'chat.delete',
                    'chat.ban',
                    'chat.timeout',
                    'chat.slow_mode',
                    'server.manage',
                    'server.create',
                    'server.delete',
                    'user.manage',
                    'user.view',
                    'user.edit',
                    'user.delete',
                    'role.manage',
                    'role.assign',
                    'stream.manage',
                    'stream.force_stop',
                    'metrics.view',
                    'logs.view',
                ],
                'metadata' => [
                    'badge' => 'ADMIN',
                    'icon' => 'shield',
                ],
            ]
        );

        // Moderator role with chat and user management permissions
        Role::updateOrCreate(
            ['slug' => 'moderator'],
            [
                'name' => 'Moderator',
                'description' => 'Chat and user moderator with limited permissions',
                'chat_color' => '#00FF00', // Green color for moderators
                'priority' => 50, // High priority but less than admin
                'external_id' => null, // Set the provider's group ID to sync this role at login
                'is_visible' => true,
                'permissions' => [
                    'filament.access',
                    'chat.moderate',
                    'chat.delete',
                    'chat.timeout',
                    'chat.slow_mode',
                    'user.view',
                    'user.timeout',
                    'stream.view',
                    'metrics.view',
                ],
                'metadata' => [
                    'badge' => 'MOD',
                    'icon' => 'gavel',
                ],
            ]
        );

        // Staff role (general convention staff)
        Role::updateOrCreate(
            ['slug' => 'staff'],
            [
                'name' => 'Staff',
                'description' => 'General convention staff member',
                'chat_color' => '#3B82F6', // Blue color for staff
                'priority' => 30,
                'external_id' => null, // Set the provider's group ID to sync this role at login
                'is_visible' => true,
                'permissions' => [
                    'chat.send',
                    'stream.view',
                ],
                'metadata' => [
                    'badge' => 'STAFF',
                    'icon' => 'badge',
                ],
            ]
        );

        // Supersponsor role (assigned at login from registration system)
        Role::updateOrCreate(
            ['slug' => 'supersponsor'],
            [
                'name' => 'Super Sponsor',
                'description' => 'Event super sponsor',
                'chat_color' => '#83559e', // Purple color for super sponsors
                'priority' => 28,
                'external_id' => null, // Set the registration package name to sync this role at login
                'is_visible' => true,
                'permissions' => [
                    'chat.bypass_slow_mode',
                    'chat.bypass_cooldown',
                ],
                'metadata' => [
                    'badge' => 'SUPER',
                    'icon' => 'crown',
                ],
            ]
        );

        // Sponsor role (can be assigned at login from registration system)
        Role::updateOrCreate(
            ['slug' => 'sponsor'],
            [
                'name' => 'Sponsor',
                'description' => 'Event sponsor',
                'chat_color' => '#f6cb21', // Yellow/Gold color for sponsors
                'priority' => 25,
                'external_id' => null, // Set the registration package name to sync this role at login
                'is_visible' => true,
                'permissions' => [
                    'chat.bypass_slow_mode',
                ],
                'metadata' => [
                    'badge' => 'SPONSOR',
                    'icon' => 'heart',
                ],
            ]
        );

        // Attendee role (default role for all registered attendees)
        Role::updateOrCreate(
            ['slug' => 'attendee'],
            [
                'name' => 'Attendee',
                'description' => 'Registered attendee',
                'chat_color' => null, // Use default chat color
                'priority' => 10,
                'external_id' => null, // Set the registration package name to sync this role at login
                'is_visible' => false, // Don't show badge for regular attendees
                'permissions' => [
                    'chat.send',
                    'stream.view',
                ],
                'metadata' => [],
            ]
        );

        // Digital Pass role for authenticated users without tickets
        Role::updateOrCreate(
            ['slug' => 'digital-pass'],
            [
                'name' => 'Digital Pass',
                'description' => 'Authenticated user without ticket',
                'chat_color' => null, // Use default chat color
                'priority' => 5, // Lower priority than attendee
                'is_visible' => false, // Don't show badge
                'permissions' => [
                    'chat.send',
                    'stream.view',
                ],
                'metadata' => [],
            ]
        );
    }
}
