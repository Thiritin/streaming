<?php

namespace App\Policies;

use App\Enum\ServerStatusEnum;
use App\Models\Server;
use App\Models\User;

/**
 * Who may do what to streaming servers.
 *
 * Reading is open to anyone who passed the `access-manage` gate, matching what the
 * Filament panel allowed. Mutations are narrowed to `stream.manage` (or an admin),
 * which is a deliberate tightening: the old panel let anyone holding only
 * `filament.access` - a chat moderator, for instance - deprovision infrastructure.
 * See docs/admin/rebuild-plan.md 2.9.
 */
class ServerPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Server $server): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $this->manages($user);
    }

    public function update(User $user, Server $server): bool
    {
        return $this->manages($user);
    }

    /**
     * Only manually managed servers are deleted outright. A Hetzner server has to go
     * through deprovisioning so the cloud resource and its DNS record are cleaned up.
     */
    public function delete(User $user, Server $server): bool
    {
        return $this->manages($user) && ! $server->isHetznerServer();
    }

    public function deprovision(User $user, Server $server): bool
    {
        return $this->manages($user) && $server->isHetznerServer();
    }

    /**
     * The escape hatch for a teardown that stalled: skip straight to deleting the
     * cloud resources. Offered only once the row is already `deprovisioning`, so it
     * cannot be used to bypass taking the edge out of rotation first.
     */
    public function forceDeprovision(User $user, Server $server): bool
    {
        return $this->deprovision($user, $server)
            && $server->status === ServerStatusEnum::DEPROVISIONING;
    }

    /**
     * Provisioning is not tied to one record.
     */
    public function provision(User $user): bool
    {
        return $this->manages($user);
    }

    public function viewInstallScript(User $user, Server $server): bool
    {
        return $this->manages($user);
    }

    private function manages(User $user): bool
    {
        return $user->hasPermission('stream.manage') || $user->hasPermission('admin.access') || $user->isStaff();
    }
}
