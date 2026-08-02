<?php

namespace Tests\Unit\Services;

use App\Enum\PlaybackTokenTypeEnum;
use App\Exceptions\InvalidPlaybackTokenException;
use App\Models\User;
use App\Services\PlaybackTokenService;
use App\Support\PlaybackToken;
use Illuminate\Support\Facades\Config;
use RuntimeException;
use Tests\TestCase;

class PlaybackTokenServiceTest extends TestCase
{
    private PlaybackTokenService $tokens;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('stream.token.viewer_secret', str_repeat('a', 64));
        Config::set('stream.token.embed_secret', str_repeat('b', 64));
        Config::set('stream.token.ttl', 900);
        Config::set('stream.token.leeway', 60);
        Config::set('stream.token.refresh_margin', 180);

        $this->tokens = new PlaybackTokenService;
    }

    private function user(int $id = 42): User
    {
        $user = new User;
        $user->id = $id;

        return $user;
    }

    public function test_it_round_trips_a_viewer_token(): void
    {
        $encoded = $this->tokens->issueViewer($this->user(7), 'main-stage', edge: 'edge-1.example.org');

        $token = $this->tokens->verify($encoded, 'main-stage');

        $this->assertSame(PlaybackTokenTypeEnum::VIEWER, $token->type);
        $this->assertSame('main-stage', $token->source);
        $this->assertSame('7', $token->subject);
        $this->assertSame('edge-1.example.org', $token->edge);
        $this->assertNotNull($token->sessionId);
        $this->assertNull($token->keyId);
        $this->assertGreaterThan(0, $token->expiresIn());
    }

    public function test_encoded_token_is_url_safe(): void
    {
        $encoded = $this->tokens->issueViewer($this->user(), 'main-stage');

        $this->assertSame($encoded, urlencode($encoded));
        $this->assertStringStartsWith(PlaybackTokenService::VERSION.'.', $encoded);
    }

    public function test_it_round_trips_an_embed_token_without_expiry(): void
    {
        $encoded = $this->tokens->issueEmbed('vrchat-main-stage', 'main-stage');

        $token = $this->tokens->verify($encoded, 'main-stage');

        $this->assertSame(PlaybackTokenTypeEnum::EMBED, $token->type);
        $this->assertSame('vrchat-main-stage', $token->keyId);
        $this->assertNull($token->expiresAt);
        $this->assertNull($token->expiresIn());
        $this->assertFalse($token->isExpired());
    }

    public function test_it_rejects_a_tampered_payload(): void
    {
        $encoded = $this->tokens->issueViewer($this->user(), 'main-stage');
        [$version, $payload, $signature] = explode('.', $encoded);

        $forged = $this->encode(json_encode([
            'typ' => 'viewer',
            'src' => 'staff-only',
            'sub' => '1',
            'exp' => time() + 900,
        ]));

        $this->assertRejected(
            InvalidPlaybackTokenException::BAD_SIGNATURE,
            "{$version}.{$forged}.{$signature}"
        );
    }

    public function test_it_rejects_a_token_signed_with_the_wrong_secret(): void
    {
        $encoded = $this->tokens->issueViewer($this->user(), 'main-stage');

        Config::set('stream.token.viewer_secret', str_repeat('c', 64));

        $this->assertRejected(InvalidPlaybackTokenException::BAD_SIGNATURE, $encoded);
    }

    public function test_an_embed_token_cannot_pass_as_a_viewer_token(): void
    {
        // Claiming a different type only changes which secret is checked, so the
        // signature must still fail. This is what keeps the two secrets separate.
        $encoded = $this->tokens->issueEmbed('vrchat-main-stage', 'main-stage');
        [$version, $payload] = explode('.', $encoded);

        $claims = json_decode($this->decode($payload), true);
        $claims['typ'] = 'viewer';
        $claims['exp'] = time() + 900;

        $forged = $version.'.'.$this->encode(json_encode($claims));
        $signature = $this->encode(hash_hmac('sha256', $forged, str_repeat('b', 64), true));

        $this->assertRejected(InvalidPlaybackTokenException::BAD_SIGNATURE, $forged.'.'.$signature);
    }

    public function test_it_rejects_an_expired_token_past_the_leeway(): void
    {
        $encoded = $this->tokens->issueViewer($this->user(), 'main-stage', ttl: -120);

        $this->assertRejected(InvalidPlaybackTokenException::EXPIRED, $encoded);
    }

    public function test_it_accepts_a_just_expired_token_within_the_leeway(): void
    {
        $encoded = $this->tokens->issueViewer($this->user(), 'main-stage', ttl: -10);

        $token = $this->tokens->verify($encoded);

        $this->assertLessThan(0, $token->expiresIn());
    }

    public function test_it_rejects_a_token_bound_to_another_source(): void
    {
        $encoded = $this->tokens->issueViewer($this->user(), 'main-stage');

        $this->assertRejected(InvalidPlaybackTokenException::SOURCE_MISMATCH, $encoded, 'staff-only');
    }

    public function test_it_rejects_a_viewer_token_with_no_expiry(): void
    {
        $encoded = $this->tokens->issue(new PlaybackToken(
            type: PlaybackTokenTypeEnum::VIEWER,
            source: 'main-stage',
            subject: '1',
        ));

        $this->assertRejected(InvalidPlaybackTokenException::MISSING_EXPIRY, $encoded);
    }

    public function test_it_rejects_a_downgraded_version(): void
    {
        $encoded = $this->tokens->issueViewer($this->user(), 'main-stage');
        [, $payload, $signature] = explode('.', $encoded);

        $this->assertRejected(
            InvalidPlaybackTokenException::UNSUPPORTED_VERSION,
            "v0.{$payload}.{$signature}"
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function malformedTokens(): array
    {
        return [
            'empty' => [''],
            'one segment' => ['v1'],
            'two segments' => ['v1.abc'],
            'four segments' => ['v1.abc.def.ghi'],
            'non base64 payload' => ['v1.!!!.abc'],
            'payload is not json' => ['v1.bm90anNvbg.abc'],
        ];
    }

    /**
     * @dataProvider malformedTokens
     */
    public function test_it_rejects_malformed_tokens(string $encoded): void
    {
        $this->expectException(InvalidPlaybackTokenException::class);
        $this->tokens->verify($encoded);
    }

    public function test_try_verify_returns_null_instead_of_throwing(): void
    {
        $this->assertNull($this->tokens->tryVerify('garbage'));
        $this->assertNotNull($this->tokens->tryVerify(
            $this->tokens->issueViewer($this->user(), 'main-stage')
        ));
    }

    public function test_it_reports_whether_a_secret_is_configured(): void
    {
        $this->assertTrue($this->tokens->isConfigured());
        $this->assertTrue($this->tokens->isConfigured(PlaybackTokenTypeEnum::EMBED));

        Config::set('stream.token.viewer_secret', null);

        $this->assertFalse($this->tokens->isConfigured());
        $this->assertTrue($this->tokens->isConfigured(PlaybackTokenTypeEnum::EMBED));
    }

    public function test_it_fails_loudly_when_a_secret_is_missing(): void
    {
        Config::set('stream.token.viewer_secret', '');

        $this->expectException(RuntimeException::class);
        $this->tokens->issueViewer($this->user(), 'main-stage');
    }

    public function test_refresh_happens_before_expiry(): void
    {
        $this->assertSame(720, $this->tokens->refreshAfter());
        $this->assertLessThan($this->tokens->ttl(), $this->tokens->refreshAfter());
    }

    public function test_refresh_never_goes_negative_on_a_short_ttl(): void
    {
        Config::set('stream.token.ttl', 60);

        $this->assertSame(60, $this->tokens->refreshAfter());
    }

    /**
     * Assert that verifying $encoded is rejected for exactly $reason, so a test
     * cannot pass because the token failed for some unrelated reason.
     */
    private function assertRejected(string $reason, string $encoded, ?string $expectedSource = null): void
    {
        try {
            $this->tokens->verify($encoded, $expectedSource);
        } catch (InvalidPlaybackTokenException $e) {
            $this->assertSame($reason, $e->reason, "Rejected for [{$e->reason}] instead of [{$reason}].");

            return;
        }

        $this->fail("Expected the token to be rejected for [{$reason}], but it verified.");
    }

    private function encode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private function decode(string $encoded): string
    {
        return base64_decode(strtr($encoded, '-_', '+/'), true);
    }
}
