<?php

namespace App\Jobs\Server\Provision;

use App\Enum\ServerStatusEnum;
use App\Enum\ServerTypeEnum;
use App\Models\Server;
use App\Services\Cloud\CloudManager;
use App\Services\Cloud\ServerSpec;
use App\Services\ServerProvisioningService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;

class CreateVirtualMachineJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public readonly Server $server) {}

    public function handle(CloudManager $cloud): void
    {
        // The provider the installation is set to now. Everything downstream reads it
        // off the row instead, so a change here never reaches a machine that exists.
        $provider = $cloud->driver();

        $name = $this->server->type->value.'-'.$this->server->id.'-'.Str::random(12);

        // Mint the credentials here rather than at row creation: the plaintext exists
        // only for as long as this render, and this is the render that hands it to the
        // box. Anything the row held before is invalidated, which is correct - no VM has
        // booted with it yet.
        $this->server->issueCredentials();

        $role = $this->server->type === ServerTypeEnum::EDGE ? 'edge' : 'origin';

        $spec = new ServerSpec(
            role: $this->server->type->value,
            name: $name,
            // Chosen per server when provisioning from /manage, falling back to the
            // per-role default. It used to be hardcoded here, which made instance size a
            // deploy-time decision for something billed by the hour.
            size: $this->server->size(),
            location: config('stream.server.location'),
            userData: app(ServerProvisioningService::class)->generateCloudInit($this->server),
            hostname: $this->server->hostname,
            ip: $this->server->ip,
        );

        /*
         * The row learns its provider before the machine exists, not after. `tries = 1`,
         * so a crash between the API call and the write would otherwise leave a billing
         * machine nothing points at - and with the id column no longer a convention,
         * nothing to find it by either. The provider's own label carries the row id for
         * the same reason, so an orphan is identifiable in the provider's console.
         */
        $this->server->update(['provider' => $provider->name()]);

        $created = $provider->create($this->server, $spec);

        $this->server->update([
            'provider' => $provider->name(),
            'external_id' => $created->externalId,
            // Kept in step for one release: edges already in the field POST against it
            // and the manage table still searches it. Only for a machine a provider
            // actually built - writing `manual:{id}` into it would poison the column the
            // provider backfill reads, which treats a non-empty value as "this is cloud".
            'hetzner_id' => $provider->supportsProvisioning() ? $created->externalId : null,
            'hostname' => $spec->hostname && ! $provider->supportsProvisioning()
                ? $spec->hostname
                : trim($name.'.'.config('dns.zone'), '.'),
            'ip' => $created->ip ?: $this->server->ip,
            'internal_ip' => $created->internalIp,
            'port' => 443,
            'max_clients' => config("stream.server.max_clients.{$role}", $role === 'edge' ? 100 : 1000),
            'status' => ServerStatusEnum::PROVISIONING,
        ]);

        // One chain, declared here. CreateServerJob used to chain the same two jobs on
        // top of these, so a provision through it created the DNS record twice - which,
        // while the record was written with a bare `update add`, left two A records for
        // one hostname.
        Bus::chain([
            new AwaitPublicAddressJob($this->server),
            new CreateDnsRecordJob($this->server),
            new WaitUntilServerIsReadyJob($this->server),
        ])->dispatch();
    }
}
