<?php

namespace Tests\Unit\Services\Auth;

use App\Models\AuthProvider;
use App\Services\Auth\OidcProvider;
use App\Services\Auth\ProviderFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * The generic OIDC driver Socialite has no core case for: where it gets its endpoints
 * from, and what it refuses to guess at.
 */
class OidcProviderTest extends TestCase
{
    use RefreshDatabase;

    private function discovery(): void
    {
        Http::fake([
            '*/.well-known/openid-configuration' => Http::response([
                'authorization_endpoint' => 'https://identity.example.org/oauth2/auth',
                'token_endpoint' => 'https://identity.example.org/oauth2/token',
                'userinfo_endpoint' => 'https://identity.example.org/userinfo',
            ]),
        ]);
    }

    private function driver(array $overrides = []): OidcProvider
    {
        $provider = AuthProvider::factory()->create(array_merge([
            'key' => 'custom',
            'issuer_url' => 'https://identity.example.org',
        ], $overrides));

        return app(ProviderFactory::class)->make($provider);
    }

    public function test_the_endpoints_come_from_the_discovery_document(): void
    {
        $this->discovery();

        $this->assertSame(
            'https://identity.example.org/oauth2/token',
            $this->driver()->endpoint('token_endpoint'),
        );
    }

    /**
     * For a provider whose discovery is wrong or absent. The override wins, and the
     * document is never fetched.
     */
    public function test_an_explicit_endpoint_wins_over_discovery(): void
    {
        $this->discovery();

        $driver = $this->driver([
            'endpoints' => ['token_endpoint' => 'https://elsewhere.example.org/token'],
        ]);

        $this->assertSame('https://elsewhere.example.org/token', $driver->endpoint('token_endpoint'));
    }

    public function test_a_provider_that_publishes_nothing_fails_loudly(): void
    {
        Http::fake(['*' => Http::response([], 404)]);

        $this->expectException(RuntimeException::class);

        $this->driver()->endpoint('token_endpoint');
    }

    public function test_the_authorize_url_carries_the_rows_redirect_and_scopes(): void
    {
        $this->discovery();

        $driver = $this->driver(['scopes' => ['groups']]);

        $url = $driver->stateless()->redirect()->getTargetUrl();

        $this->assertStringStartsWith('https://identity.example.org/oauth2/auth?', $url);
        parse_str(parse_url($url, PHP_URL_QUERY), $query);

        $this->assertSame('streaming', $query['client_id']);
        $this->assertSame(route('auth.provider.callback', 'custom'), $query['redirect_uri']);
        // Merged onto the driver's own, so a row asking for one more still gets the
        // three OIDC needs.
        $this->assertSame('openid profile email groups', $query['scope']);
    }

    /**
     * The document is cached per row, so switching one provider's issuer cannot hand
     * another provider's endpoints back.
     */
    public function test_the_discovery_cache_is_keyed_per_provider(): void
    {
        Http::fake([
            'first.example.org/*' => Http::response(['token_endpoint' => 'https://first.example.org/token']),
            'second.example.org/*' => Http::response(['token_endpoint' => 'https://second.example.org/token']),
        ]);

        $first = $this->driver(['key' => 'first', 'issuer_url' => 'https://first.example.org']);
        $second = $this->driver(['key' => 'second', 'issuer_url' => 'https://second.example.org']);

        $this->assertSame('https://first.example.org/token', $first->endpoint('token_endpoint'));
        $this->assertSame('https://second.example.org/token', $second->endpoint('token_endpoint'));
    }
}
