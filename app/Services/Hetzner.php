<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use LKDev\HetznerCloud\HetznerAPIClient;

class Hetzner
{
    /** How long the instance-size list is trusted before Hetzner is asked again. */
    private const CATALOGUE_TTL = 3600;

    public static function client(): HetznerAPIClient
    {
        return new HetznerAPIClient(config('services.hetzner.token'));
    }

    /**
     * Instance sizes Hetzner can actually place in our location, right now.
     *
     * A hardcoded list goes stale in two different ways, and both have bitten. Hetzner
     * retires a generation - the whole `cpx21`/`cpx31`/`cpx41` line stopped being
     * placeable in every EU datacenter - and it also runs out of a type temporarily.
     * Either way the provisioning job gets `422 unsupported location for server type`,
     * which surfaces as a server row stuck at `pending` with no IP and a failed job
     * nobody is watching.
     *
     * So the dropdown is built from what the API says is available, and the validation
     * behind it uses the same list. Cached for an hour, because this renders on a page
     * load and the catalogue changes on the order of months.
     *
     * Falls back to `stream.server.types` if the API is unreachable: a stale list is
     * better than an empty dropdown when someone is trying to provision under pressure.
     *
     * @return array<string, string> size => human label
     */
    public static function availableServerTypes(): array
    {
        $fallback = config('stream.server.types', []);

        // No token means no catalogue to ask for - tests and local development, where
        // reaching out would be a slow round trip to a 401.
        if (! config('services.hetzner.token')) {
            return $fallback;
        }

        return Cache::remember('hetzner.server_types', self::CATALOGUE_TTL, function () use ($fallback) {
            try {
                return self::fetchAvailableServerTypes() ?: $fallback;
            } catch (\Throwable $e) {
                Log::warning('Could not read the Hetzner size catalogue, using the configured list: '.$e->getMessage());

                return config('stream.server.types', []);
            }
        });
    }

    /**
     * @return array<string, string>
     */
    private static function fetchAvailableServerTypes(): array
    {
        $token = config('services.hetzner.token');
        $location = config('stream.server.location', 'nbg1');

        $client = Http::withToken($token)->timeout(10);

        $types = $client->get('https://api.hetzner.cloud/v1/server_types', ['per_page' => 100])
            ->throw()->json('server_types', []);

        $datacenters = $client->get('https://api.hetzner.cloud/v1/datacenters', ['per_page' => 50])
            ->throw()->json('datacenters', []);

        // "Available" is per datacenter, not per location, and a location can hold more
        // than one. A size placeable in any datacenter of our location will do.
        $placeable = [];
        foreach ($datacenters as $dc) {
            if (($dc['location']['name'] ?? null) !== $location) {
                continue;
            }
            foreach ($dc['server_types']['available'] ?? [] as $id) {
                $placeable[$id] = true;
            }
        }

        $options = [];
        foreach ($types as $type) {
            if (! isset($placeable[$type['id']])) {
                continue;
            }

            // ARM sizes are cheap and useless here: the transcoder and uploader images
            // are built for x86 only, so an arm64 box would fail on image pull.
            if (($type['architecture'] ?? 'x86') !== 'x86') {
                continue;
            }

            $hourly = (float) ($type['prices'][0]['price_hourly']['gross'] ?? 0);

            $options[$type['name']] = sprintf(
                '%s - %d vCPU / %d GB (%s) - EUR %.3f/hr',
                $type['name'],
                $type['cores'],
                $type['memory'],
                ($type['cpu_type'] ?? '') === 'dedicated' ? 'dedicated' : 'shared',
                $hourly,
            );
        }

        // Cheapest first: the list is read by someone deciding what to spend.
        uksort($options, function ($a, $b) use ($types) {
            $price = function ($name) use ($types) {
                foreach ($types as $t) {
                    if ($t['name'] === $name) {
                        return (float) ($t['prices'][0]['price_hourly']['gross'] ?? 0);
                    }
                }

                return 0.0;
            };

            return $price($a) <=> $price($b);
        });

        return $options;
    }
}
