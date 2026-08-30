<?php

namespace App\Support\Auth;

/**
 * OAuth2 providers refuse a plain-http redirect URI unless the host is localhost or a
 * `*.localhost` subdomain. Ory Hydra answers with `invalid_request: Redirect URL is
 * using an insecure protocol ...` only after a full round trip through the authorize
 * endpoint, which reads like a login failure rather than a misconfigured APP_URL.
 * Catch it before leaving the app.
 */
final class RedirectUri
{
    /**
     * The reason the provider will refuse this URI, or null when it is acceptable.
     */
    public static function rejection(string $uri): ?string
    {
        $parts = parse_url($uri);

        if (($parts['scheme'] ?? 'http') === 'https') {
            return null;
        }

        $host = $parts['host'] ?? '';

        if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
            return null;
        }

        return "Sign-in is misconfigured: the callback URL {$uri} uses http, which the identity "
            .'provider only accepts for localhost hosts. Set APP_URL to an https URL, or to a '
            .'*.localhost host, and make sure that callback URL is registered for this client.';
    }
}
