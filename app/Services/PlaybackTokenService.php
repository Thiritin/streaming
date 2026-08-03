<?php

namespace App\Services;

use App\Enum\PlaybackTokenTypeEnum;
use App\Exceptions\InvalidPlaybackTokenException;
use App\Models\Source;
use App\Models\User;
use App\Support\PlaybackToken;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Mints and verifies playback tokens.
 *
 * The wire format is `v1.<base64url(claims json)>.<base64url(hmac sha256)>`.
 * The version prefix is inside the signed body so it cannot be downgraded, and
 * verification needs nothing but the shared secret. That is what lets an edge
 * check a token locally with njs instead of calling back into Laravel.
 *
 * See docs/streaming-auth-redesign.md.
 */
class PlaybackTokenService
{
    public const VERSION = 'v1';

    /**
     * Issue a token for an attendee watching a source in the web player.
     *
     * Callers must have already checked entitlement (Show::canBeAccessedBy) -
     * the source binding on the token is what carries that decision forward.
     */
    public function issueViewer(
        User $user,
        Source|string $source,
        ?string $edge = null,
        ?string $sessionId = null,
        ?int $ttl = null,
    ): string {
        return $this->issue(new PlaybackToken(
            type: PlaybackTokenTypeEnum::VIEWER,
            source: $this->slug($source),
            subject: (string) $user->getKey(),
            edge: $edge,
            sessionId: $sessionId ?? (string) Str::uuid(),
            expiresAt: time() + ($ttl ?? $this->ttl()),
        ));
    }

    /**
     * Issue a token for a signed-out viewer.
     *
     * Only reachable on an installation with `auth.required` off, and only for
     * a source the caller has already established carries no role restriction.
     * The token has no subject, which is exactly what "we do not know who this
     * is" means; edges never look at `sub`, they only check the source binding.
     */
    public function issueGuest(
        Source|string $source,
        ?string $edge = null,
        ?string $sessionId = null,
        ?int $ttl = null,
    ): string {
        return $this->issue(new PlaybackToken(
            type: PlaybackTokenTypeEnum::VIEWER,
            source: $this->slug($source),
            edge: $edge,
            sessionId: $sessionId ?? (string) Str::uuid(),
            expiresAt: time() + ($ttl ?? $this->ttl()),
        ));
    }

    /**
     * Issue a key for an external embed.
     *
     * No expiry by default: the URL is baked into a VRChat world and can never
     * be rotated, so revocation runs through the key-id allowlist that edges
     * refresh instead. Pass $ttl only for a deliberately temporary embed.
     */
    public function issueEmbed(
        string $keyId,
        Source|string $source,
        ?string $edge = null,
        ?int $ttl = null,
    ): string {
        return $this->issue(new PlaybackToken(
            type: PlaybackTokenTypeEnum::EMBED,
            source: $this->slug($source),
            keyId: $keyId,
            edge: $edge,
            expiresAt: $ttl === null ? null : time() + $ttl,
        ));
    }

    public function issue(PlaybackToken $token): string
    {
        $body = self::VERSION.'.'.$this->encode($this->json($token->claims()));

        return $body.'.'.$this->encode($this->sign($body, $token->type));
    }

    /**
     * Verify a token and return its claims.
     *
     * @param  string|null  $expectedSource  Slug the request is actually asking for.
     *
     * @throws InvalidPlaybackTokenException
     */
    public function verify(string $encoded, ?string $expectedSource = null): PlaybackToken
    {
        $parts = explode('.', $encoded);

        if (count($parts) !== 3) {
            throw InvalidPlaybackTokenException::malformed('expected three segments');
        }

        [$version, $payload, $signature] = $parts;

        if (! hash_equals(self::VERSION, $version)) {
            throw InvalidPlaybackTokenException::unsupportedVersion($version);
        }

        $claims = $this->decodeClaims($payload);

        // The type is read from the not-yet-verified payload only to choose which
        // secret to check against. Claiming the wrong type simply fails the
        // signature check below, so this cannot be used to cross the two secrets.
        $type = PlaybackTokenTypeEnum::tryFrom((string) ($claims['typ'] ?? ''));

        if ($type === null) {
            throw InvalidPlaybackTokenException::malformed('unknown token type');
        }

        $expected = $this->sign($version.'.'.$payload, $type);
        $actual = $this->decode($signature);

        if ($actual === null || ! hash_equals($expected, $actual)) {
            throw InvalidPlaybackTokenException::badSignature();
        }

        $token = PlaybackToken::fromClaims($claims);

        if ($token->isExpired($this->leeway())) {
            throw InvalidPlaybackTokenException::expired();
        }

        if ($expectedSource !== null && $token->source !== $expectedSource) {
            throw InvalidPlaybackTokenException::sourceMismatch($expectedSource, $token->source);
        }

        return $token;
    }

    /**
     * Verify without throwing, for hot paths that only care whether to answer 403.
     */
    public function tryVerify(string $encoded, ?string $expectedSource = null): ?PlaybackToken
    {
        try {
            return $this->verify($encoded, $expectedSource);
        } catch (InvalidPlaybackTokenException) {
            return null;
        }
    }

    /**
     * Whether a secret is configured for this token type. Callers on user-facing
     * paths check this first so an environment without secrets keeps working
     * instead of erroring; only code that genuinely requires a token lets
     * secret() throw.
     */
    public function isConfigured(PlaybackTokenTypeEnum $type = PlaybackTokenTypeEnum::VIEWER): bool
    {
        $secret = config($type->secretConfigKey());

        return is_string($secret) && $secret !== '';
    }

    public function ttl(): int
    {
        return (int) config('stream.token.ttl');
    }

    public function leeway(): int
    {
        return (int) config('stream.token.leeway');
    }

    /**
     * Seconds after issue at which the client should ask for a fresh token. The
     * gap before expiry is what the 403 recovery path gets to work with if the
     * push over the websocket never arrives.
     */
    public function refreshAfter(): int
    {
        return max(60, $this->ttl() - (int) config('stream.token.refresh_margin'));
    }

    private function secret(PlaybackTokenTypeEnum $type): string
    {
        $key = $type->secretConfigKey();
        $secret = config($key);

        if (! is_string($secret) || $secret === '') {
            throw new RuntimeException(
                "Missing playback token secret [{$key}]. Set the matching HLS_*_SECRET in the environment."
            );
        }

        return $secret;
    }

    private function sign(string $body, PlaybackTokenTypeEnum $type): string
    {
        return hash_hmac('sha256', $body, $this->secret($type), true);
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function json(array $claims): string
    {
        return json_encode($claims, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws InvalidPlaybackTokenException
     */
    private function decodeClaims(string $payload): array
    {
        $json = $this->decode($payload);

        if ($json === null) {
            throw InvalidPlaybackTokenException::malformed('payload is not valid base64url');
        }

        try {
            $claims = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw InvalidPlaybackTokenException::malformed('payload is not valid json');
        }

        if (! is_array($claims)) {
            throw InvalidPlaybackTokenException::malformed('payload is not a claim set');
        }

        return $claims;
    }

    private function encode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private function decode(string $encoded): ?string
    {
        $decoded = base64_decode(strtr($encoded, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }

    private function slug(Source|string $source): string
    {
        return $source instanceof Source ? $source->slug : $source;
    }
}
