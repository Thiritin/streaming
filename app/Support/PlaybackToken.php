<?php

namespace App\Support;

use App\Enum\PlaybackTokenTypeEnum;
use App\Exceptions\InvalidPlaybackTokenException;

/**
 * The claims carried by a playback token.
 *
 * A token is bound to exactly one source. Entitlement (a show's required_roles)
 * is checked once at mint time, so the binding itself is the authorisation and
 * an edge never has to know about roles. See docs/streaming-auth-redesign.md.
 */
final readonly class PlaybackToken
{
    public function __construct(
        public PlaybackTokenTypeEnum $type,
        /** Source slug this token may play, never a wildcard. */
        public string $source,
        /** User id, for viewer tokens. */
        public ?string $subject = null,
        /** Embed key id, for embed tokens. Edges check it against a pushed allowlist. */
        public ?string $keyId = null,
        /** Edge hostname this session is pinned to, when one has been chosen. */
        public ?string $edge = null,
        /** Playback session id, used for counting only, never for auth. */
        public ?string $sessionId = null,
        /** Unix timestamp. Required for viewer tokens, optional for embed keys. */
        public ?int $expiresAt = null,
    ) {}

    /**
     * Seconds until expiry, negative once past it. Null when the token does not expire.
     */
    public function expiresIn(?int $now = null): ?int
    {
        if ($this->expiresAt === null) {
            return null;
        }

        return $this->expiresAt - ($now ?? time());
    }

    /**
     * Leeway absorbs clock drift between the app and an edge, and a refresh that
     * lands a little late. Edges apply the same window.
     */
    public function isExpired(int $leeway = 0, ?int $now = null): bool
    {
        if ($this->expiresAt === null) {
            return false;
        }

        return ($now ?? time()) > $this->expiresAt + $leeway;
    }

    public function isViewer(): bool
    {
        return $this->type === PlaybackTokenTypeEnum::VIEWER;
    }

    public function isEmbed(): bool
    {
        return $this->type === PlaybackTokenTypeEnum::EMBED;
    }

    /**
     * Wire format. Null claims are dropped so the encoded token stays short
     * enough to sit comfortably in a query string.
     *
     * @return array<string, string|int>
     */
    public function claims(): array
    {
        return array_filter([
            'typ' => $this->type->value,
            'src' => $this->source,
            'sub' => $this->subject,
            'kid' => $this->keyId,
            'edge' => $this->edge,
            'sid' => $this->sessionId,
            'exp' => $this->expiresAt,
        ], static fn ($value) => $value !== null);
    }

    /**
     * @param  array<string, mixed>  $claims
     *
     * @throws InvalidPlaybackTokenException
     */
    public static function fromClaims(array $claims): self
    {
        $type = PlaybackTokenTypeEnum::tryFrom((string) ($claims['typ'] ?? ''));

        if ($type === null) {
            throw InvalidPlaybackTokenException::malformed('unknown token type');
        }

        $source = $claims['src'] ?? null;

        if (! is_string($source) || $source === '') {
            throw InvalidPlaybackTokenException::malformed('missing source binding');
        }

        $expiresAt = $claims['exp'] ?? null;

        if ($expiresAt !== null && ! is_int($expiresAt)) {
            throw InvalidPlaybackTokenException::malformed('expiry is not an integer');
        }

        if ($expiresAt === null && $type->requiresExpiry()) {
            throw InvalidPlaybackTokenException::missingExpiry();
        }

        return new self(
            type: $type,
            source: $source,
            subject: self::optionalString($claims, 'sub'),
            keyId: self::optionalString($claims, 'kid'),
            edge: self::optionalString($claims, 'edge'),
            sessionId: self::optionalString($claims, 'sid'),
            expiresAt: $expiresAt,
        );
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private static function optionalString(array $claims, string $key): ?string
    {
        $value = $claims[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if (! is_string($value) && ! is_int($value)) {
            throw InvalidPlaybackTokenException::malformed("claim [{$key}] is not a scalar");
        }

        return (string) $value;
    }
}
