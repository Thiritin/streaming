<?php

namespace App\Console\Commands\Chat;

use App\Models\User;
use App\Models\Role;
use App\Events\UserRoleUpdatedEvent;
use Illuminate\Support\Facades\Log;

class BadgeCommand extends AbstractChatCommand
{
    protected string $name = 'badge';
    protected array $aliases = [];
    protected string $description = 'Grant or revoke role-based badges for users';
    protected string $signature = '/badge <action> <username> <role>';

    protected array $parameters = [
        'action' => [
            'required' => true,
            'type' => 'string',
            'description' => 'Action to perform (grant/revoke)',
        ],
        'username' => [
            'required' => true,
            'type' => 'string',
            'description' => 'Username to modify badges for',
        ],
        'role' => [
            'required' => true,
            'type' => 'string',
            'description' => 'Role slug to grant/revoke (admin, moderator, sponsor, supersponsor, staff)',
        ],
    ];

    public function rules(): array
    {
        return [
            'action' => 'required|string|in:grant,revoke',
            'username' => 'required|string',
            'role' => 'required|string|exists:roles,slug',
        ];
    }

    public function authorize(User $user): bool
    {
        return $user->hasPermission('role.assign') || 
               $user->hasRole('admin');
    }

    protected function execute(User $user, array $parameters): void
    {
        $action = strtolower($parameters['action']);
        $username = $parameters['username'];
        $roleSlug = strtolower($parameters['role']);

        // Validate action
        if (!in_array($action, ['grant', 'revoke'])) {
            $this->feedback($user, "Invalid action. Use 'grant' or 'revoke'.", 'error');
            return;
        }

        // Find role
        $role = Role::where('slug', $roleSlug)->first();
        if (!$role) {
            $this->feedback($user, "Role '{$roleSlug}' not found.", 'error');
            return;
        }

        // Find target user
        $targetUser = User::where('name', $username)->first();
        if (!$targetUser) {
            $this->feedback($user, "User '{$username}' not found.", 'error');
            return;
        }

        if ($action === 'grant') {
            $this->grantRole($user, $targetUser, $role);
        } else {
            $this->revokeRole($user, $targetUser, $role);
        }
    }

    private function grantRole(User $grantor, User $targetUser, Role $role): void
    {
        // Check if user already has the role
        if ($targetUser->hasRole($role->slug)) {
            $this->feedback($grantor, "User already has the {$role->name} role.", 'warning');
            return;
        }

        // Attach role to user
        $targetUser->roles()->attach($role->id);

        // Clear user's role cache
        \Cache::forget("user_roles_{$targetUser->id}");

        // Broadcast update (using existing UserRoleUpdatedEvent if it exists)
        if (class_exists('App\Events\UserRoleUpdatedEvent')) {
            broadcast(new UserRoleUpdatedEvent($targetUser->id, $targetUser->roles->toArray()));
        }

        // Send feedback
        $this->feedback($grantor, "Granted {$role->name} role to {$targetUser->name}.", 'success');
        $this->feedback($targetUser, "You have been granted the {$role->name} role!", 'success');

        // Log the action
        Log::info('Role granted', [
            'grantor_id' => $grantor->id,
            'target_user_id' => $targetUser->id,
            'role_slug' => $role->slug,
        ]);
    }

    private function revokeRole(User $revoker, User $targetUser, Role $role): void
    {
        // Check if user has the role
        if (!$targetUser->hasRole($role->slug)) {
            $this->feedback($revoker, "User does not have the {$role->name} role.", 'warning');
            return;
        }

        // Detach role from user
        $targetUser->roles()->detach($role->id);

        // Clear user's role cache
        \Cache::forget("user_roles_{$targetUser->id}");

        // Broadcast update (using existing UserRoleUpdatedEvent if it exists)
        if (class_exists('App\Events\UserRoleUpdatedEvent')) {
            broadcast(new UserRoleUpdatedEvent($targetUser->id, $targetUser->roles->toArray()));
        }

        // Send feedback
        $this->feedback($revoker, "Revoked {$role->name} role from {$targetUser->name}.", 'success');
        $this->feedback($targetUser, "Your {$role->name} role has been revoked.", 'warning');

        // Log the action
        Log::info('Role revoked', [
            'revoker_id' => $revoker->id,
            'target_user_id' => $targetUser->id,
            'role_slug' => $role->slug,
        ]);
    }

    public function examples(): array
    {
        return [
            '/badge grant JohnDoe moderator' => 'Grant moderator role to JohnDoe',
            '/badge revoke JohnDoe sponsor' => 'Revoke sponsor role from JohnDoe',
            '/badge grant ArtistName staff' => 'Grant staff role to ArtistName',
        ];
    }
}