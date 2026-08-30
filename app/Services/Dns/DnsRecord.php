<?php

namespace App\Services\Dns;

/**
 * One record, as every driver takes it. `hostname` is the fully qualified name; a
 * driver that speaks in labels relative to its zone strips the zone itself.
 */
final class DnsRecord
{
    public function __construct(
        public readonly string $hostname,
        public readonly string $type = 'A',
        public readonly ?string $value = null,
        public readonly int $ttl = 60,
    ) {}
}
