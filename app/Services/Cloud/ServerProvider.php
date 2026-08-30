<?php

namespace App\Services\Cloud;

use App\Models\Server;
use App\Services\DriverCheck;

interface ServerProvider
{
    /**
     * May return before an address exists; the caller polls status().
     */
    public function create(Server $server, ServerSpec $spec): ProvisionedServer;

    public function status(string $externalId): ServerStatus;

    /**
     * Already gone is success, the same way an absent DNS record is.
     */
    public function delete(string $externalId): void;

    /**
     * @return array<string, string> size => label. Empty when the driver has no catalogue.
     */
    public function sizes(): array;

    /**
     * @return array<string, string> location => label. Empty when not applicable.
     */
    public function locations(): array;

    /**
     * False for bring-your-own: the panel asks for an address instead of a size.
     */
    public function supportsProvisioning(): bool;

    public function check(): DriverCheck;

    public function name(): string;
}
