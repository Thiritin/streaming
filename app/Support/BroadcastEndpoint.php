<?php

namespace App\Support;

class BroadcastEndpoint
{
    /**
     * The websocket endpoint handed to Echo in the browser.
     *
     * REVERB_HOST points the server at Reverb from inside the deployment, which in
     * Kubernetes is a cluster-internal service name no browser can resolve. Use the
     * explicit REVERB_CLIENT_* override when an installation sets one, the server's
     * own host when that is something a browser can reach (local dev), and the app's
     * own domain otherwise, where the ingress routes /app to Reverb.
     */
    public static function forBrowser(): array
    {
        $options = config('broadcasting.connections.reverb.options');
        $client = config('broadcasting.connections.reverb.client');

        $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);
        $appScheme = parse_url((string) config('app.url'), PHP_URL_SCHEME) ?: 'https';

        if (! empty($client['host'])) {
            $host = $client['host'];
            $scheme = $client['scheme'] ?: 'https';
            $port = (int) ($client['port'] ?: ($scheme === 'https' ? 443 : 80));
        } elseif (self::reachableFromBrowser($options['host'] ?? null)) {
            $host = $options['host'];
            $scheme = $options['scheme'] ?: 'https';
            $port = (int) ($options['port'] ?: ($scheme === 'https' ? 443 : 80));
        } else {
            $host = $appHost;
            $scheme = $appScheme;
            $port = $scheme === 'https' ? 443 : 80;
        }

        return [
            'key' => config('broadcasting.connections.reverb.key'),
            'host' => $host,
            'port' => $port,
            'scheme' => $scheme,
        ];
    }

    /**
     * A public name or an address, as opposed to a bare container or service name.
     */
    protected static function reachableFromBrowser(?string $host): bool
    {
        if (blank($host)) {
            return false;
        }

        return $host === 'localhost' || str_contains($host, '.') || str_contains($host, ':');
    }
}
