<?php

namespace App\Services\Cloud;

/**
 * Where a machine is, and what it answers on if it answers on anything yet.
 *
 * The addresses ride along with the state because the caller wants both in one round
 * trip: the poll that decides whether provisioning can continue is the same poll that
 * finds the public address.
 */
final class ServerStatus
{
    public function __construct(
        public readonly ServerState $state,
        public readonly ?string $ip = null,
        public readonly ?string $internalIp = null,
    ) {}

    public function isRunning(): bool
    {
        return $this->state === ServerState::Running;
    }
}
