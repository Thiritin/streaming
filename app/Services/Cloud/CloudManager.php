<?php

namespace App\Services\Cloud;

use App\Services\Cloud\Drivers\HetznerServerDriver;
use App\Services\Cloud\Drivers\ManualServerDriver;
use Illuminate\Support\Manager;

/**
 * Who builds the machine.
 *
 * Provisioning resolves the driver the installation is set to now. Everything acting on
 * an existing server resolves the driver named on the row: resolving the current one
 * instead would mean switching the installation to `manual` orphans every running VM -
 * still billing, invisible to the panel, deletable only by hand in the provider's
 * console.
 */
class CloudManager extends Manager
{
    public function getDefaultDriver(): string
    {
        return (string) $this->config->get('stream.server.provider', 'manual');
    }

    /**
     * Never cached. Credentials come from the settings table and RuntimeConfig re-applies
     * them between jobs, so a driver held from the last resolution would keep the values
     * it was built with for the life of an Octane worker or a queue process.
     */
    public function driver($driver = null)
    {
        return $this->createDriver($driver ?: $this->getDefaultDriver());
    }

    /**
     * The driver a server was built by, falling back to the current one for a row that
     * predates the column.
     */
    public function forServer(?string $provider): ServerProvider
    {
        return $this->driver($provider ?: null);
    }

    protected function createHetznerDriver(): ServerProvider
    {
        return new HetznerServerDriver(
            (string) $this->config->get('services.hetzner.token'),
            (string) $this->config->get('stream.server.location', 'nbg1'),
            (string) $this->config->get('stream.server.image', 'ubuntu-22.04'),
            $this->config->get('stream.server.ssh_key'),
            $this->config->get('stream.server.network'),
        );
    }

    protected function createManualDriver(): ServerProvider
    {
        return new ManualServerDriver;
    }

    /**
     * An unknown name is a machine nothing here can act on, which is exactly what the
     * manual driver is. Better than throwing on every teardown in the fleet.
     */
    protected function createDriver($driver)
    {
        try {
            return parent::createDriver($driver);
        } catch (\InvalidArgumentException) {
            return $this->createManualDriver();
        }
    }
}
