<?php

namespace App\Services\Dns\Drivers;

use App\Services\Dns\DnsProvider;
use App\Services\Dns\DnsRecord;
use App\Services\DriverCheck;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Cloudflare's DNS API.
 *
 * Every write is `proxied: false`. An orange-cloud record answers with Cloudflare's
 * anycast address, which intercepts the ACME challenge a server takes its certificate
 * with and hides the edge's real address from the placement model at the same time.
 * Nothing here should ever offer that as a choice.
 */
final class CloudflareDriver implements DnsProvider
{
    private const API = 'https://api.cloudflare.com/client/v4';

    /** How long a resolved zone id is trusted. It changes when the zone is recreated. */
    private const ZONE_TTL = 3600;

    public function __construct(
        private readonly string $token,
        private readonly string $zone,
        private readonly ?string $zoneId = null,
    ) {}

    public function name(): string
    {
        return 'cloudflare';
    }

    public function zone(): string
    {
        return $this->zone;
    }

    public function upsert(DnsRecord $record): void
    {
        $zoneId = $this->requireZoneId();
        $existing = $this->findAll($zoneId, $record);

        $payload = [
            'type' => $record->type,
            'name' => $record->hostname,
            'content' => $record->value,
            'ttl' => $record->ttl,
            'proxied' => false,
        ];

        // POST is create-only, so a name that already exists is a PUT onto its id.
        // Without this a retried provision would answer 81057 and abort the chain.
        if ($existing === []) {
            $this->client()->post(self::API."/zones/{$zoneId}/dns_records", $payload)->throw();

            return;
        }

        // One survives with the right address and the rest go. A name that is already
        // duplicated - which is the state the old additive write left behind - has to
        // come out of this call pointing at one machine, or the resolver goes on
        // round-robining between a live box and an address somebody else now holds.
        $this->client()->put(self::API."/zones/{$zoneId}/dns_records/".array_shift($existing), $payload)->throw();

        foreach ($existing as $id) {
            $this->client()->delete(self::API."/zones/{$zoneId}/dns_records/{$id}")->throw();
        }
    }

    public function delete(DnsRecord $record): void
    {
        $zoneId = $this->requireZoneId();

        // Every match, not the first: teardown has to leave the name resolving to
        // nothing, and a second record left behind outlives the machine it named.
        foreach ($this->findAll($zoneId, $record) as $id) {
            $this->client()->delete(self::API."/zones/{$zoneId}/dns_records/{$id}")->throw();
        }
    }

    public function resolve(string $hostname): ?string
    {
        $zoneId = $this->zoneId();

        if ($zoneId === null) {
            return null;
        }

        // Throws rather than answering null on an API error: the create job reads a null
        // as "the record is not there", so a refused token would be logged in the same
        // words as a propagation delay.
        $records = $this->client()
            ->get(self::API."/zones/{$zoneId}/dns_records", ['type' => 'A', 'name' => $hostname])
            ->throw()
            ->json('result', []);

        return $records[0]['content'] ?? null;
    }

    public function check(): DriverCheck
    {
        $details = ['Zone' => $this->zone];

        if ($this->token === '') {
            return DriverCheck::fail('Set an API token first.', $details);
        }

        try {
            $zoneId = $this->zoneId();

            if ($zoneId === null) {
                return DriverCheck::fail('The token does not reach a zone with that name.', $details);
            }

            $details['Zone ID'] = $zoneId;

            $zone = $this->client()->get(self::API."/zones/{$zoneId}")->throw()->json('result', []);

            $details['Status'] = (string) ($zone['status'] ?? 'unknown');
        } catch (\Throwable $e) {
            return DriverCheck::fail('Cloudflare refused the request: '.$e->getMessage(), $details);
        }

        if (($zone['status'] ?? null) !== 'active') {
            return DriverCheck::fail('The zone is not active on this account.', $details);
        }

        return DriverCheck::pass('Zone reachable. Records are written unproxied.', $details);
    }

    private function client(): PendingRequest
    {
        return Http::withToken($this->token)->acceptJson()->timeout(10);
    }

    /**
     * Every record id for this name and type, in the order Cloudflare lists them.
     *
     * @return array<int, string>
     */
    private function findAll(string $zoneId, DnsRecord $record): array
    {
        $records = $this->client()
            ->get(self::API."/zones/{$zoneId}/dns_records", ['type' => $record->type, 'name' => $record->hostname])
            ->throw()
            ->json('result', []);

        return collect($records)->pluck('id')->filter()->map(fn ($id) => (string) $id)->values()->all();
    }

    private function requireZoneId(): string
    {
        $zoneId = $this->zoneId();

        if ($zoneId === null) {
            throw new \RuntimeException("Cloudflare holds no zone named {$this->zone} for this token.");
        }

        return $zoneId;
    }

    private function zoneId(): ?string
    {
        if ($this->zoneId !== null && $this->zoneId !== '') {
            return $this->zoneId;
        }

        return Cache::remember('dns.cloudflare.zone.'.md5($this->token.'|'.$this->zone), self::ZONE_TTL, function () {
            $zones = $this->client()->get(self::API.'/zones', ['name' => $this->zone])->throw()->json('result', []);

            return $zones[0]['id'] ?? null;
        });
    }
}
