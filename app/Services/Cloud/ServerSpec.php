<?php

namespace App\Services\Cloud;

/**
 * What to build, in terms every provider can answer for. The manual driver reads only
 * `hostname` and `ip`; everything else is a cloud API's vocabulary.
 */
final class ServerSpec
{
    public function __construct(
        public readonly string $role,
        public readonly string $name,
        public readonly ?string $size = null,
        public readonly ?string $location = null,
        public readonly string $userData = '',
        public readonly ?string $hostname = null,
        public readonly ?string $ip = null,
    ) {}
}
