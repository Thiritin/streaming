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
     * Deleted outright only when there is genuinely nothing to tear down: no machine a
     * provider owns, and no DNS record anybody wrote.
     *
     * A manually managed server still gets an A record in our zone, so dropping its row
     * leaves the name resolving to an address the operator no longer controls. That is
     * the subdomain takeover this project already lived through with origin-1, and it
     * does not care who owned the machine.
     */
    public function delete(User $user, Server $server): bool
    {
        return $this->manages($user) && ! $server->isCloud() && $server->dnsProvider() === null;
    }

    /**
     * Anything with something to remove: a machine, a record, or both. The manual
     * driver's delete is a no-op, so a bring-your-own server runs the same chain and
     * reaches DELETED with its record gone.
     */
    public function deprovision(User $user, Server $server): bool
    {
        return $this->manages($user) && ($server->isCloud() || $server->dnsProvider() !== null);
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

    /**
     * Rotating stops the running box from checking in until it is reinstalled, so it is
     * a mutation and gated as one.
     */
    public function rotateCredentials(User $user, Server $server): bool
    {
        return $this->manages($user);
    }

    private function manages(User $user): bool
    {
        return $user->hasPermission('stream.manage') || $user->hasPermission('admin.access') || $user->isStaff();
    }
}
