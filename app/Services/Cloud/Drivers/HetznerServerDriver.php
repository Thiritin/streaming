<?php

namespace App\Services\Cloud\Drivers;

use App\Models\Server;
use App\Services\Cloud\ProvisionedServer;
use App\Services\Cloud\ServerProvider;
use App\Services\Cloud\ServerSpec;
use App\Services\Cloud\ServerState;
use App\Services\Cloud\ServerStatus;
use App\Services\DriverCheck;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Hetzner Cloud.
 *
 * Everything is Laravel's Http client. The provisioning call used to be a raw Guzzle
 * POST alongside the SDK, which is why it had no test at all - it could not be faked.
 *
 * The SSH key, the private network, the image and the location are settings rather than
 * literals: they name things in one particular Hetzner project, and a literal in a job
 * is a project this installation may not be.
 */
final class HetznerServerDriver implements ServerProvider
{
    private const API = 'https://api.hetzner.cloud/v1';

    /** How long the instance-size list is trusted before Hetzner is asked again. */
    private const CATALOGUE_TTL = 3600;

    public function __construct(
        private readonly string $token,
        private readonly string $location,
        private readonly string $image,
        private readonly ?string $sshKeyName,
        private readonly ?string $networkName,
    ) {}

    public function name(): string
    {
        return 'hetzner';
    }

    public function supportsProvisioning(): bool
    {
        return true;
    }

    public function create(Server $server, ServerSpec $spec): ProvisionedServer
    {
        $payload = [
            'name' => $spec->name,
            'server_type' => $spec->size,
            'image' => $this->image,
            'location' => $spec->location ?: $this->location,
            'user_data' => $spec->userData,
            'start_after_create' => true,
            // The row id rides along so an orphan - a machine created by a job that
            // crashed before it could write the id back - is identifiable in the
            // console rather than being one unlabelled VM among several.
            'labels' => ['type' => $spec->role, 'server' => (string) $server->id],
        ];

        // Both are looked up by name and both are optional. A miss warns and provisions
        // without: no key means nobody can log in by hand, no network means edges reach
        // the origin over its public address. Neither is worth failing a provision for.
        if ($sshKey = $this->lookup('ssh_keys', $this->sshKeyName)) {
            $payload['ssh_keys'] = [$sshKey];
        }

        if ($network = $this->lookup('networks', $this->networkName)) {
            $payload['networks'] = [$network];
        }

        $created = $this->client()->post(self::API.'/servers', $payload)->throw()->json('server', []);

        return new ProvisionedServer(
            externalId: (string) ($created['id'] ?? ''),
            ip: $created['public_net']['ipv4']['ip'] ?? null,
            internalIp: $created['private_net'][0]['ip'] ?? null,
            metadata: [
                'datacenter' => $created['datacenter']['name'] ?? null,
                'image' => $this->image,
            ],
        );
    }

    public function status(string $externalId): ServerStatus
    {
        $response = $this->client()->get(self::API."/servers/{$externalId}");

        if ($response->status() === 404) {
            return new ServerStatus(ServerState::Gone);
        }

        $server = $response->throw()->json('server', []);

        $ip = $server['public_net']['ipv4']['ip'] ?? null;

        return new ServerStatus(
            $ip ? ServerState::Running : ServerState::Pending,
            $ip,
            $server['private_net'][0]['ip'] ?? null,
        );
    }

    public function delete(string $externalId): void
    {
        $response = $this->client()->delete(self::API."/servers/{$externalId}");

        // A VM that is already gone is the state this exists to reach, so a 404 is
        // success. Treating it as a failure left the row stuck in `deprovisioning`
        // forever with a failed job behind it, which is what happened twice in September
        // 2025 and again when an operator deleted a server by hand in the console.
        if ($response->status() === 404) {
            Log::info('Hetzner server was already gone; treating deprovision as complete', [
                'external_id' => $externalId,
            ]);

            return;
        }

        $response->throw();
    }

    /**
     * Instance sizes Hetzner can actually place in our location, right now.
     *
     * A hardcoded list goes stale in two different ways, and both have bitten. Hetzner
     * retires a generation - the whole `cpx21`/`cpx31`/`cpx41` line stopped being
     * placeable in every EU datacenter - and it also runs out of a type temporarily.
     * Either way the provisioning call gets `422 unsupported location for server type`,
     * which surfaces as a server row stuck at `pending` with no IP and a failed job
     * nobody is watching.
     *
     * Falls back to `stream.server.types` if the API is unreachable: a stale list is
     * better than an empty dropdown when someone is trying to provision under pressure.
     *
     * @return array<string, string> size => human label
     */
    public function sizes(): array
    {
        $fallback = config('stream.server.types', []);

        // No token means no catalogue to ask for - tests and local development, where
        // reaching out would be a slow round trip to a 401.
        if ($this->token === '') {
            return $fallback;
        }

        // Keyed on the location as well as the token: "placeable" is per location, so a
        // location change used to serve the old one's sizes for an hour and the provision
        // answered 422 unsupported location for server type.
        $key = 'hetzner.server_types.'.md5($this->token.'|'.$this->location);

        return Cache::remember($key, self::CATALOGUE_TTL, function () use ($fallback) {
            try {
                return $this->fetchSizes() ?: $fallback;
            } catch (\Throwable $e) {
                Log::warning('Could not read the Hetzner size catalogue, using the configured list: '.$e->getMessage());

                return config('stream.server.types', []);
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function locations(): array
    {
        if ($this->token === '') {
            return [];
        }

        try {
            $locations = $this->client()->get(self::API.'/locations')->throw()->json('locations', []);
        } catch (\Throwable) {
            return [];
        }

        $options = [];

        foreach ($locations as $location) {
            $options[$location['name']] = sprintf(
                '%s - %s, %s',
                $location['name'],
                $location['city'] ?? '',
                $location['country'] ?? '',
            );
        }

        return $options;
    }

    public function check(): DriverCheck
    {
        $details = ['Location' => $this->location, 'Image' => $this->image];

        if ($this->token === '') {
            return DriverCheck::fail('Set an API token first.', $details);
        }

        try {
            $project = $this->client()->get(self::API.'/servers', ['per_page' => 1])->throw()->json('meta', []);
        } catch (\Throwable $e) {
            return DriverCheck::fail('Hetzner refused the request: '.$e->getMessage(), $details);
        }

        $details['Servers in project'] = (string) ($project['pagination']['total_entries'] ?? 0);

        // Both are named rather than identified, so a rename in the project is a silent
        // downgrade: the provision succeeds and the machine comes up without them.
        if ($this->sshKeyName && ! $this->lookup('ssh_keys', $this->sshKeyName)) {
            $details['SSH key'] = 'Not found in this project';
        }

        if ($this->networkName && ! $this->lookup('networks', $this->networkName)) {
            $details['Private network'] = 'Not found in this project';
        }

        return DriverCheck::pass('Token accepted.', $details);
    }

    /**
     * The id of a named resource, or null when there is no name set or no match. Never
     * throws: a missing key or network downgrades the machine, it does not stop it.
     */
    private function lookup(string $collection, ?string $name): ?int
    {
        if ($name === null || trim($name) === '') {
            return null;
        }

        try {
            $found = $this->client()->get(self::API.'/'.$collection, ['name' => $name])->throw()->json($collection, []);
        } catch (\Throwable $e) {
            Log::warning("Could not look up {$collection} '{$name}': ".$e->getMessage());

            return null;
        }

        if (($found[0]['id'] ?? null) === null) {
            Log::warning("Hetzner holds no {$collection} named '{$name}'; provisioning without it");

            return null;
        }

        return (int) $found[0]['id'];
    }

    /**
     * @return array<string, string>
     */
    private function fetchSizes(): array
    {
        $types = $this->client()->get(self::API.'/server_types', ['per_page' => 100])
            ->throw()->json('server_types', []);

        $datacenters = $this->client()->get(self::API.'/datacenters', ['per_page' => 50])
            ->throw()->json('datacenters', []);

        // "Available" is per datacenter, not per location, and a location can hold more
        // than one. A size placeable in any datacenter of our location will do.
        $placeable = [];
        foreach ($datacenters as $dc) {
            if (($dc['location']['name'] ?? null) !== $this->location) {
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

    private function client(): PendingRequest
    {
        return Http::withToken($this->token)->acceptJson()->timeout(30);
    }
}
