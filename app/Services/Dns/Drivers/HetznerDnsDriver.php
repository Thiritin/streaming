<?php

namespace App\Services\Dns\Drivers;

use App\Services\Dns\DnsProvider;
use App\Services\Dns\DnsRecord;
use App\Services\DriverCheck;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Hetzner's DNS console API, which is a separate product from Hetzner Cloud and takes
 * a token of its own. Holding a Cloud account does not mean the zone is delegated to
 * Hetzner's nameservers, and a token that works against a zone nothing delegates to is
 * the failure this one has.
 *
 * Records are named by their label relative to the zone, so the fully qualified name a
 * DnsRecord carries is trimmed on the way in and rebuilt on the way out.
 */
final class HetznerDnsDriver implements DnsProvider
{
    private const API = 'https://dns.hetzner.com/api/v1';

    private const ZONE_TTL = 3600;

    /** What Hetzner DNS pages at, and what it is asked for per page. */
    private const PAGE_SIZE = 100;

    public function __construct(
        private readonly string $token,
        private readonly string $zone,
        private readonly ?string $zoneId = null,
    ) {}

    public function name(): string
    {
        return 'hetzner';
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
            'zone_id' => $zoneId,
            'type' => $record->type,
            'name' => $this->label($record->hostname),
            'value' => $record->value,
            'ttl' => $record->ttl,
        ];

        // POST is create-only here too, so an existing name is a PUT onto its id.
        if ($existing === []) {
            $this->client()->post(self::API.'/records', $payload)->throw();

            return;
        }

        // One survives with the right address and the rest go, for the same reason as
        // on Cloudflare: a name that is already duplicated has to come out of this call
        // pointing at exactly one machine.
        $this->client()->put(self::API.'/records/'.array_shift($existing), $payload)->throw();

        foreach ($existing as $id) {
            $this->client()->delete(self::API."/records/{$id}")->throw();
        }
    }

    public function delete(DnsRecord $record): void
    {
        $zoneId = $this->requireZoneId();

        // Every match, not the first: a second record left behind outlives the machine.
        foreach ($this->findAll($zoneId, $record) as $id) {
            $this->client()->delete(self::API."/records/{$id}")->throw();
        }
    }

    public function resolve(string $hostname): ?string
    {
        $zoneId = $this->zoneId();

        if ($zoneId === null) {
            return null;
        }

        foreach ($this->records($zoneId) as $record) {
            if (($record['type'] ?? null) === 'A' && ($record['name'] ?? null) === $this->label($hostname)) {
                return $record['value'] ?? null;
            }
        }

        return null;
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
                return DriverCheck::fail('The token reaches no zone with that name.', $details);
            }

            $details['Zone ID'] = $zoneId;

            $zone = $this->client()->get(self::API."/zones/{$zoneId}")->throw()->json('zone', []);
        } catch (\Throwable $e) {
            return DriverCheck::fail('Hetzner DNS refused the request: '.$e->getMessage(), $details);
        }

        $details['Status'] = (string) ($zone['status'] ?? 'unknown');

        // "verified" is Hetzner's word for the zone's nameservers being its own. Anything
        // else means the records are written and nothing on the internet reads them.
        if (($zone['status'] ?? null) !== 'verified') {
            return DriverCheck::fail('The zone is not delegated to Hetzner nameservers.', $details);
        }

        return DriverCheck::pass('Zone reachable and delegated.', $details);
    }

    private function client(): PendingRequest
    {
        return Http::withHeaders(['Auth-API-Token' => $this->token])->acceptJson()->timeout(10);
    }

    /**
     * Every record id for this name and type.
     *
     * @return array<int, string>
     */
    private function findAll(string $zoneId, DnsRecord $record): array
    {
        $ids = [];

        foreach ($this->records($zoneId) as $existing) {
            if (($existing['type'] ?? null) === $record->type
                && ($existing['name'] ?? null) === $this->label($record->hostname)
                && ($existing['id'] ?? null) !== null) {
                $ids[] = (string) $existing['id'];
            }
        }

        return $ids;
    }

    /**
     * Every record in the zone, paged.
     *
     * Hetzner DNS has no name filter on this endpoint, so the match is made here - and
     * it pages at 100. A fleet zone passes that, at which point an unpaged read answers
     * "no such record" for a name that exists: the upsert then POSTs a duplicate and the
     * delete leaves the record behind, which is the orphan this driver exists to avoid.
     *
     * @return array<int, array<string, mixed>>
     */
    private function records(string $zoneId): array
    {
        $records = [];
        $page = 1;

        do {
            $response = $this->client()
                ->get(self::API.'/records', ['zone_id' => $zoneId, 'page' => $page, 'per_page' => self::PAGE_SIZE])
                ->throw();

            $batch = $response->json('records', []);
            $records = [...$records, ...$batch];

            $lastPage = (int) ($response->json('meta.pagination.last_page') ?? $page);
            $page++;

            // The pagination block is not always present; a short page is the other
            // end of the list either way.
        } while ($page <= $lastPage && count($batch) === self::PAGE_SIZE);

        return $records;
    }

    /**
     * The label a fully qualified name has inside this zone. The zone apex is '@'.
     */
    private function label(string $hostname): string
    {
        $hostname = rtrim($hostname, '.');
        $zone = rtrim($this->zone, '.');

        if ($hostname === $zone || $zone === '') {
            return '@';
        }

        return str_ends_with($hostname, '.'.$zone)
            ? substr($hostname, 0, -(strlen($zone) + 1))
            : $hostname;
    }

    private function requireZoneId(): string
    {
        $zoneId = $this->zoneId();

        if ($zoneId === null) {
            throw new \RuntimeException("Hetzner DNS holds no zone named {$this->zone} for this token.");
        }

        return $zoneId;
    }

    private function zoneId(): ?string
    {
        if ($this->zoneId !== null && $this->zoneId !== '') {
            return $this->zoneId;
        }

        return Cache::remember('dns.hetzner.zone.'.md5($this->token.'|'.$this->zone), self::ZONE_TTL, function () {
            $zones = $this->client()->get(self::API.'/zones', ['name' => $this->zone])->throw()->json('zones', []);

            return $zones[0]['id'] ?? null;
        });
    }
}
