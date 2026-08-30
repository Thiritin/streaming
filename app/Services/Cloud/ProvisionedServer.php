<?php

namespace App\Services\Cloud;

/**
 * What came back. The addresses may both be null: a cloud API answers before the
 * machine has one, which is what AwaitPublicAddressJob polls for.
 */
final class ProvisionedServer
{
    /**
     * @param  array<string, mixed>  $metadata  Anything worth keeping that is not a column.
     */
    public function __construct(
        public readonly string $externalId,
        public readonly ?string $ip = null,
        public readonly ?string $internalIp = null,
        public readonly array $metadata = [],
    ) {}
}
