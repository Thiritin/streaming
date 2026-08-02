<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a playback token cannot be trusted.
 *
 * The reason is deliberately coarse and never surfaced to the client: an edge
 * or controller answers 403 regardless, so a caller cannot use the response to
 * learn which part of a forged token was wrong.
 */
class InvalidPlaybackTokenException extends RuntimeException
{
    public const MALFORMED = 'malformed';

    public const UNSUPPORTED_VERSION = 'unsupported_version';

    public const BAD_SIGNATURE = 'bad_signature';

    public const EXPIRED = 'expired';

    public const MISSING_EXPIRY = 'missing_expiry';

    public const SOURCE_MISMATCH = 'source_mismatch';

    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function malformed(string $detail): self
    {
        return new self(self::MALFORMED, "Playback token is malformed: {$detail}.");
    }

    public static function unsupportedVersion(string $version): self
    {
        return new self(self::UNSUPPORTED_VERSION, "Playback token version [{$version}] is not supported.");
    }

    public static function badSignature(): self
    {
        return new self(self::BAD_SIGNATURE, 'Playback token signature does not verify.');
    }

    public static function expired(): self
    {
        return new self(self::EXPIRED, 'Playback token has expired.');
    }

    public static function missingExpiry(): self
    {
        return new self(self::MISSING_EXPIRY, 'Playback token is missing a required expiry.');
    }

    public static function sourceMismatch(string $expected, string $actual): self
    {
        return new self(
            self::SOURCE_MISMATCH,
            "Playback token is bound to source [{$actual}], not [{$expected}]."
        );
    }
}
