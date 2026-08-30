<?php

namespace App\Services\Auth;

use App\Models\AuthProvider;
use App\Services\OpenIDService;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\ProviderInterface;
use Laravel\Socialite\Two\User;
use RuntimeException;

/**
 * The generic OIDC case, which Socialite has no core driver for.
 *
 * Writing it rather than keeping league/oauth2-client beside Socialite is what leaves
 * one callback controller, one token exchange and one user shape at the linking site.
 * Two paths would mean implementing the collision rule twice.
 */
class OidcProvider extends AbstractProvider implements ProviderInterface
{
    /**
     * `email` is what viewer notifications are addressed to. A provider that will not
     * release the claim simply leaves the address empty.
     */
    protected $scopes = ['openid', 'profile', 'email'];

    protected $scopeSeparator = ' ';

    protected ?AuthProvider $row = null;

    /**
     * The row whose endpoints this driver answers from. Set after construction because
     * Socialite's buildProvider() formats config for OAuth2 and passes nothing else.
     */
    public function forRow(AuthProvider $row): static
    {
        $this->row = $row;

        return $this;
    }

    protected function getAuthUrl($state): string
    {
        return $this->buildAuthUrlFromBase($this->endpoint('authorization_endpoint'), $state);
    }

    protected function getTokenUrl(): string
    {
        return $this->endpoint('token_endpoint');
    }

    protected function getUserByToken($token): array
    {
        $response = $this->getHttpClient()->get($this->endpoint('userinfo_endpoint'), [
            'headers' => [
                'Authorization' => 'Bearer '.$token,
                'Accept' => 'application/json',
            ],
        ]);

        return (array) json_decode((string) $response->getBody(), true);
    }

    /**
     * @param  array<string, mixed>  $user
     */
    protected function mapUserToObject(array $user): User
    {
        return (new User)->setRaw($user)->map([
            'id' => $user['sub'] ?? null,
            'nickname' => $user['preferred_username'] ?? null,
            'name' => $user['name'] ?? null,
            'email' => $user['email'] ?? null,
            // `avatar` is what this convention's provider releases; `picture` is what
            // the specification names.
            'avatar' => $user['avatar'] ?? $user['picture'] ?? null,
        ]);
    }

    /**
     * An endpoint, from the row's explicit override first and the discovery document
     * second. The override is for a provider whose discovery is wrong or absent.
     */
    public function endpoint(string $name): string
    {
        $row = $this->row;

        if ($row === null) {
            throw new RuntimeException('No provider row was given to the OIDC driver.');
        }

        $explicit = $row->endpoints[$name] ?? null;

        if (filled($explicit)) {
            return (string) $explicit;
        }

        $discovered = OpenIDService::discover($row)[$name] ?? null;

        if (blank($discovered)) {
            throw new RuntimeException("The provider released no {$name}.");
        }

        return (string) $discovered;
    }
}
