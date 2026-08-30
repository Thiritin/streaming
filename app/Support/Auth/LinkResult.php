<?php

namespace App\Support\Auth;

use App\Models\User;
use App\Models\UserIdentity;

/**
 * What IdentityLinker decided. Either an account to sign in or attach to, or a
 * sentence for whoever pressed the button.
 */
final class LinkResult
{
    private function __construct(
        public readonly ?User $user,
        public readonly ?UserIdentity $identity,
        public readonly ?string $error,
        public readonly bool $created,
    ) {}

    public static function ok(User $user, UserIdentity $identity, bool $created = false): self
    {
        return new self($user, $identity, null, $created);
    }

    public static function refused(string $error): self
    {
        return new self(null, null, $error, false);
    }

    public function failed(): bool
    {
        return $this->error !== null;
    }
}
