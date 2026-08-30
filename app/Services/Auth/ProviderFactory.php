<?php

namespace App\Services\Auth;

use App\Models\AuthProvider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\BitbucketProvider;
use Laravel\Socialite\Two\FacebookProvider;
use Laravel\Socialite\Two\GithubProvider;
use Laravel\Socialite\Two\GitlabProvider;
use Laravel\Socialite\Two\GoogleProvider;
use Laravel\Socialite\Two\LinkedInOpenIdProvider;
use Laravel\Socialite\Two\SlackOpenIdProvider;
use Laravel\Socialite\Two\XProvider;

/**
 * A Socialite driver built from a database row.
 *
 * `Socialite::driver('google')` reads config/services.php, which is useless when the
 * credentials are rows. `buildProvider()` takes a class name and a config array and
 * consults no config at all, which is the whole runtime path.
 */
class ProviderFactory
{
    /**
     * Socialite's core OAuth2 drivers, plus ours for the generic OIDC case.
     *
     * OAuth1 drivers are deliberately absent: buildProvider() formats config for
     * OAuth2 and an OAuth1 driver needs a different constructor shape.
     *
     * Discord, and anything else under socialiteproviders/, is a composer require plus
     * one line here. Discord publishes no discovery document, so the generic driver
     * does not cover it.
     *
     * @var array<string, class-string<AbstractProvider>>
     */
    public const DRIVERS = [
        'oidc' => OidcProvider::class,
        'google' => GoogleProvider::class,
        'github' => GithubProvider::class,
        'gitlab' => GitlabProvider::class,
        'bitbucket' => BitbucketProvider::class,
        'facebook' => FacebookProvider::class,
        'linkedin-openid' => LinkedInOpenIdProvider::class,
        'slack-openid' => SlackOpenIdProvider::class,
        'x' => XProvider::class,
    ];

    /**
     * What the driver select offers, in the order it offers them.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            'oidc' => 'Custom OIDC',
            'google' => 'Google',
            'github' => 'GitHub',
            'gitlab' => 'GitLab',
            'bitbucket' => 'Bitbucket',
            'facebook' => 'Facebook',
            'linkedin-openid' => 'LinkedIn',
            'slack-openid' => 'Slack',
            'x' => 'X',
        ];
    }

    public static function supports(string $driver): bool
    {
        return isset(self::DRIVERS[$driver]);
    }

    public function make(AuthProvider $provider): AbstractProvider
    {
        $built = Socialite::buildProvider(self::DRIVERS[$provider->driver], [
            'client_id' => $provider->client_id,
            'client_secret' => $provider->client_secret,
            'redirect' => $provider->redirectUrl(),
            // Merged onto the driver's own defaults, so an empty list means "whatever
            // the driver already asks for" rather than "nothing".
            'scopes' => $provider->scopes ?? [],
        ]);

        if ($built instanceof OidcProvider) {
            $built->forRow($provider);
        }

        return $built;
    }
}
