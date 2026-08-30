<?php

namespace App\Services;

use App\Models\AuthProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * An OIDC provider's discovery document, cached.
 *
 * One key per provider row, so two installations of the same software behind two
 * issuers do not share an answer, and so switching a row's issuer is one forget
 * rather than a flush.
 */
class OpenIDService
{
    private const TTL = 3600;

    /**
     * @return array<string, mixed>
     */
    public static function discover(AuthProvider $provider): array
    {
        if (blank($provider->issuer_url)) {
            return [];
        }

        return Cache::remember(self::key($provider), self::TTL, function () use ($provider) {
            $url = rtrim((string) $provider->issuer_url, '/').'/.well-known/openid-configuration';

            $response = Http::timeout(5)->get($url);

            return $response->successful() ? (array) $response->json() : [];
        });
    }

    public static function forget(AuthProvider $provider): void
    {
        Cache::forget(self::key($provider));
    }

    private static function key(AuthProvider $provider): string
    {
        return 'openid-configuration:'.$provider->key;
    }
}
