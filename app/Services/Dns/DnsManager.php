<?php

namespace App\Services\Dns;

use App\Services\Dns\Drivers\CloudflareDriver;
use App\Services\Dns\Drivers\HetznerDnsDriver;
use App\Services\Dns\Drivers\NullDriver;
use App\Services\Dns\Drivers\Rfc2136Driver;
use Illuminate\Support\Manager;

/**
 * Which DNS provider writes the records.
 *
 * Creates resolve the driver the installation is set to now. Deletes resolve the driver
 * named on the row, which is the whole reason the row records one: switching provider
 * would otherwise leave every existing record undeleteable, and a name in our zone
 * pointing at an address somebody else has since been given is worth more than an
 * untidy record.
 */
class DnsManager extends Manager
{
    public function getDefaultDriver(): string
    {
        return (string) $this->config->get('dns.driver', 'none');
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
     * The driver a record was written by, falling back to the current one for anything
     * created before the provider was recorded.
     */
    public function forRecord(?string $driver): DnsProvider
    {
        return $this->driver($driver ?: null);
    }

    protected function createRfc2136Driver(): DnsProvider
    {
        return new Rfc2136Driver(
            (string) $this->config->get('dns.server'),
            (string) $this->config->get('dns.zone'),
            (string) $this->config->get('dns.key_name'),
            (string) $this->config->get('dns.key_algorithm'),
            (string) $this->config->get('dns.key_secret'),
        );
    }

    protected function createCloudflareDriver(): DnsProvider
    {
        return new CloudflareDriver(
            (string) $this->config->get('dns.cloudflare.token'),
            (string) $this->config->get('dns.zone'),
            (string) $this->config->get('dns.cloudflare.zone_id') ?: null,
        );
    }

    protected function createHetznerDriver(): DnsProvider
    {
        return new HetznerDnsDriver(
            (string) $this->config->get('dns.hetzner.token'),
            (string) $this->config->get('dns.zone'),
            (string) $this->config->get('dns.hetzner.zone_id') ?: null,
        );
    }

    protected function createNoneDriver(): DnsProvider
    {
        return new NullDriver((string) $this->config->get('dns.zone'));
    }

    /**
     * An unknown name writes nothing rather than throwing. A driver removed from a
     * release must not turn every teardown in the fleet into a failed job.
     */
    protected function createDriver($driver)
    {
        try {
            return parent::createDriver($driver);
        } catch (\InvalidArgumentException) {
            return $this->createNoneDriver();
        }
    }
}
