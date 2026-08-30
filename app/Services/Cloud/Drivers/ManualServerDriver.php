<?php

namespace App\Services\Cloud\Drivers;

use App\Models\Server;
use App\Services\Cloud\ProvisionedServer;
use App\Services\Cloud\ServerProvider;
use App\Services\Cloud\ServerSpec;
use App\Services\Cloud\ServerState;
use App\Services\Cloud\ServerStatus;
use App\Services\DriverCheck;

/**
 * Bring your own server: the operator supplies an address and a hostname and no API is
 * called at all.
 *
 * Nothing here pretends to be asynchronous. The machine exists the moment it is named,
 * so status() answers Running immediately - but the rest of the chain is unchanged and
 * genuinely still asynchronous for a machine like this: the A record is still written
 * into our zone, and WaitUntilServerIsReadyJob still polls /health while somebody runs
 * the install script on it.
 */
final class ManualServerDriver implements ServerProvider
{
    public const PREFIX = 'manual:';

    public function name(): string
    {
        return 'manual';
    }

    public function supportsProvisioning(): bool
    {
        return false;
    }

    public function create(Server $server, ServerSpec $spec): ProvisionedServer
    {
        return new ProvisionedServer(
            externalId: self::PREFIX.$server->id,
            ip: $spec->ip,
            metadata: ['hostname' => $spec->hostname],
        );
    }

    public function status(string $externalId): ServerStatus
    {
        return new ServerStatus(ServerState::Running);
    }

    /**
     * Nothing to delete: the machine is somebody else's. The row still reaches
     * `deleted`, which is what stops a teardown sitting in `deprovisioning` forever.
     */
    public function delete(string $externalId): void {}

    public function sizes(): array
    {
        return [];
    }

    public function locations(): array
    {
        return [];
    }

    public function check(): DriverCheck
    {
        return DriverCheck::pass(
            'No API is called. Addresses and hostnames are entered by hand.',
            // The generated config and install script carry the viewer and embed
            // secrets, the system streamkey and the archive bucket credentials onto
            // whatever machine runs them.
            ['Secrets' => 'The generated install script puts this installation\'s keys on hardware it does not own'],
        );
    }
}
