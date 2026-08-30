<?php

namespace Tests\Feature;

use App\Enum\ServerStatusEnum;
use App\Enum\ServerTypeEnum;
use App\Jobs\Server\Deprovision\DeleteVirtualMachineJob;
use App\Jobs\Server\Provision\AwaitPublicAddressJob;
use App\Jobs\Server\Provision\CreateDnsRecordJob;
use App\Jobs\Server\Provision\CreateVirtualMachineJob;
use App\Jobs\Server\Provision\WaitUntilServerIsReadyJob;
use App\Models\Server;
use App\Services\Cloud\CloudManager;
use App\Services\Cloud\ServerState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesManageUsers;
use Tests\TestCase;

/**
 * The cloud drivers and the jobs that call them.
 *
 * Provisioning had no coverage at all before, because the call that created the machine
 * was a raw Guzzle POST alongside the SDK and could not be faked. That is the reason the
 * whole thing is behind Laravel's Http client now.
 */
class ServerProviderTest extends TestCase
{
    use CreatesManageUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createManageUsers();

        Config::set('dns.driver', 'none');
        Config::set('dns.zone', 'stream.example.org');
        Config::set('services.hetzner.token', 'hz-token');
        Config::set('stream.server.provider', 'hetzner');
        Config::set('stream.server.ssh_key', 'ops-key');
        Config::set('stream.server.network', 'stream');
    }

    public function test_hetzner_creates_a_machine_with_the_configured_key_and_network(): void
    {
        Bus::fake();

        Http::fake([
            'api.hetzner.cloud/v1/ssh_keys*' => Http::response(['ssh_keys' => [['id' => 7]]]),
            'api.hetzner.cloud/v1/networks*' => Http::response(['networks' => [['id' => 3]]]),
            'api.hetzner.cloud/v1/servers' => Http::response(['server' => [
                'id' => 987654,
                'public_net' => ['ipv4' => ['ip' => '203.0.113.9']],
            ]]),
        ]);

        $server = Server::factory()->create([
            'provider' => 'hetzner',
            'type' => ServerTypeEnum::EDGE,
            'server_type' => 'cx23',
            'hostname' => 'pending',
            'status' => ServerStatusEnum::PROVISIONING,
        ]);

        (new CreateVirtualMachineJob($server))->handle(app(CloudManager::class));

        Http::assertSent(function ($request) {
            return $request->method() === 'POST'
                && $request->url() === 'https://api.hetzner.cloud/v1/servers'
                && $request['server_type'] === 'cx23'
                && $request['image'] === 'ubuntu-22.04'
                && $request['ssh_keys'] === [7]
                && $request['networks'] === [3];
        });

        $server->refresh();

        $this->assertSame('hetzner', $server->provider);
        $this->assertSame('987654', $server->external_id);
        // Written in parallel for one release: edges in the field POST the old column.
        $this->assertSame('987654', $server->hetzner_id);
        $this->assertStringEndsWith('.stream.example.org', $server->hostname);
    }

    /**
     * Neither is worth failing a provision for: no key means nobody can log in by hand,
     * no network means edges reach the origin over its public address.
     */
    public function test_a_missing_ssh_key_or_network_does_not_stop_a_provision(): void
    {
        Bus::fake();

        Http::fake([
            'api.hetzner.cloud/v1/ssh_keys*' => Http::response(['ssh_keys' => []]),
            'api.hetzner.cloud/v1/networks*' => Http::response(['networks' => []]),
            'api.hetzner.cloud/v1/servers' => Http::response(['server' => ['id' => 1]]),
        ]);

        $server = Server::factory()->create(['provider' => 'hetzner', 'hostname' => 'pending']);

        (new CreateVirtualMachineJob($server))->handle(app(CloudManager::class));

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && ! isset($request['ssh_keys'])
            && ! isset($request['networks']));

        $this->assertSame('1', $server->fresh()->external_id);
    }

    /**
     * One chain. CreateServerJob used to chain the DNS and readiness jobs on top of the
     * ones declared here, so a provision through it wrote the record twice.
     */
    public function test_provisioning_chains_the_address_dns_and_readiness_jobs_once(): void
    {
        Bus::fake();

        Http::fake([
            'api.hetzner.cloud/v1/ssh_keys*' => Http::response(['ssh_keys' => []]),
            'api.hetzner.cloud/v1/networks*' => Http::response(['networks' => []]),
            'api.hetzner.cloud/v1/servers' => Http::response(['server' => ['id' => 5]]),
        ]);

        $server = Server::factory()->create(['provider' => 'hetzner', 'hostname' => 'pending']);

        (new CreateVirtualMachineJob($server))->handle(app(CloudManager::class));

        Bus::assertChained([
            AwaitPublicAddressJob::class,
            CreateDnsRecordJob::class,
            WaitUntilServerIsReadyJob::class,
        ]);
    }

    public function test_the_address_poll_retries_until_the_machine_has_one(): void
    {
        Http::fake(['api.hetzner.cloud/v1/servers/5' => Http::response(['server' => [
            'id' => 5,
            'public_net' => ['ipv4' => ['ip' => null]],
        ]])]);

        $server = Server::factory()->create(['provider' => 'hetzner', 'external_id' => '5']);

        $this->expectException(\Exception::class);

        (new AwaitPublicAddressJob($server))->handle(app(CloudManager::class));
    }

    public function test_the_address_poll_writes_both_addresses(): void
    {
        Http::fake(['api.hetzner.cloud/v1/servers/5' => Http::response(['server' => [
            'id' => 5,
            'public_net' => ['ipv4' => ['ip' => '203.0.113.9']],
            'private_net' => [['ip' => '10.0.0.4']],
        ]])]);

        $server = Server::factory()->create(['provider' => 'hetzner', 'external_id' => '5']);

        (new AwaitPublicAddressJob($server))->handle(app(CloudManager::class));

        $server->refresh();

        $this->assertSame('203.0.113.9', $server->ip);
        $this->assertSame('10.0.0.4', $server->internal_ip);
    }

    public function test_hetzner_reports_a_deleted_machine_as_gone(): void
    {
        Http::fake(['api.hetzner.cloud/*' => Http::response(['error' => []], 404)]);

        $status = app(CloudManager::class)->driver('hetzner')->status('5');

        $this->assertSame(ServerState::Gone, $status->state);
    }

    /**
     * The manual driver has to fit the chain without pretending to be asynchronous. It
     * calls nothing, and the rest of the chain runs unmodified - the A record is still
     * written into our zone, and readiness is still polled while somebody runs the
     * install script.
     */
    public function test_the_manual_driver_provisions_with_no_api_call(): void
    {
        Bus::fake();
        Http::fake();

        Config::set('stream.server.provider', 'manual');

        $server = Server::factory()->create([
            'provider' => 'manual',
            'hostname' => 'edge-byo.example.org',
            'ip' => '198.51.100.4',
            'status' => ServerStatusEnum::PROVISIONING,
        ]);

        (new CreateVirtualMachineJob($server))->handle(app(CloudManager::class));

        Http::assertNothingSent();

        $server->refresh();

        $this->assertSame('manual', $server->provider);
        $this->assertSame('manual:'.$server->id, $server->external_id);
        // The typed hostname survives: nothing composed one from the zone.
        $this->assertSame('edge-byo.example.org', $server->hostname);
        $this->assertSame('198.51.100.4', $server->ip);

        Bus::assertChained([
            AwaitPublicAddressJob::class,
            CreateDnsRecordJob::class,
            WaitUntilServerIsReadyJob::class,
        ]);
    }

    /**
     * The failure this exists to stop: switching the installation to `manual` orphaning
     * every running machine - still billing, invisible to the panel, deletable only by
     * hand in the provider's console.
     */
    public function test_the_teardown_uses_the_driver_named_on_the_row(): void
    {
        Config::set('stream.server.provider', 'manual');

        Http::fake(['api.hetzner.cloud/*' => Http::response([], 200)]);

        $server = Server::factory()->cloud()->create(['status' => ServerStatusEnum::DEPROVISIONING]);

        (new DeleteVirtualMachineJob($server))->handle(app(CloudManager::class));

        Http::assertSent(fn ($request) => $request->method() === 'DELETE'
            && str_contains($request->url(), 'api.hetzner.cloud/v1/servers/'.$server->external_id));
    }

    /**
     * A row inserted by code that predates the `provider` column - which is every insert
     * during a rolling deploy - takes the column default, `manual`. Reading that as
     * "nobody's machine" would offer no Deprovision, no-op the teardown and leave the VM
     * billing. The id is what used to carry the answer, so it still gets to.
     */
    public function test_a_row_without_a_provider_is_still_cloud_by_its_id(): void
    {
        $server = Server::factory()->create(['provider' => 'manual', 'hetzner_id' => '4242424']);

        $this->assertTrue($server->isCloud());
        // Null means "whatever the installation is set to", which is the only guess.
        $this->assertNull($server->cloudProvider());

        Http::fake(['api.hetzner.cloud/*' => Http::response([], 200)]);

        (new DeleteVirtualMachineJob($server))->handle(app(CloudManager::class));

        Http::assertSent(fn ($request) => $request->method() === 'DELETE'
            && str_contains($request->url(), '/v1/servers/4242424'));
    }

    public function test_a_genuinely_manual_row_is_not_cloud(): void
    {
        $server = Server::factory()->create(['provider' => 'manual']);
        $registered = Server::factory()->create(['provider' => 'manual', 'external_id' => 'manual:99']);

        $this->assertFalse($server->isCloud());
        $this->assertFalse($registered->isCloud());
        $this->assertSame('manual', $registered->cloudProvider());
    }

    /**
     * The chain stops here when the machine never gets an address, and a row left at
     * PROVISIONING is invisible: Overview only checks staleness on ACTIVE servers. ERROR
     * is already an alert, so the row has to say so itself.
     */
    public function test_a_machine_that_never_gets_an_address_lands_in_error(): void
    {
        $server = Server::factory()->create([
            'provider' => 'hetzner',
            'external_id' => '5',
            'status' => ServerStatusEnum::PROVISIONING,
        ]);

        (new AwaitPublicAddressJob($server))->failed(new \Exception('out of attempts'));

        $server->refresh();

        $this->assertSame(ServerStatusEnum::ERROR, $server->status);
        $this->assertNotNull($server->health_check_message);
    }

    /**
     * The row learns its provider before the machine exists. `tries = 1`, so a crash in
     * that window used to leave a billing machine nothing pointed at.
     */
    public function test_the_row_names_its_provider_before_the_machine_is_created(): void
    {
        Bus::fake();

        $server = Server::factory()->create(['provider' => 'manual', 'hostname' => 'pending']);

        Http::fake(function () use ($server) {
            $this->assertSame('hetzner', $server->fresh()->provider);

            return Http::response(['server' => ['id' => 9], 'ssh_keys' => [], 'networks' => []]);
        });

        (new CreateVirtualMachineJob($server))->handle(app(CloudManager::class));

        $this->assertSame('9', $server->fresh()->external_id);
    }

    public function test_the_created_machine_is_labelled_with_its_row(): void
    {
        Bus::fake();

        Http::fake([
            'api.hetzner.cloud/v1/ssh_keys*' => Http::response(['ssh_keys' => []]),
            'api.hetzner.cloud/v1/networks*' => Http::response(['networks' => []]),
            'api.hetzner.cloud/v1/servers' => Http::response(['server' => ['id' => 9]]),
        ]);

        $server = Server::factory()->create(['provider' => 'hetzner', 'hostname' => 'pending']);

        (new CreateVirtualMachineJob($server))->handle(app(CloudManager::class));

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && ($request['labels']['server'] ?? null) === (string) $server->id);
    }

    /**
     * The deploy-window row falls back to the installation's own driver, and that driver
     * may be one that builds nothing. Doing nothing would mark the row DELETED with the
     * machine still running and billing, and nothing would look at it again; failing
     * leaves it in `deprovisioning` with a failed job, which is a thing somebody finds.
     */
    public function test_a_cloud_row_is_never_torn_down_by_a_driver_that_builds_nothing(): void
    {
        Config::set('stream.server.provider', 'manual');

        Http::fake();

        $server = Server::factory()->create([
            'provider' => 'manual',
            'hetzner_id' => '4242424',
            'status' => ServerStatusEnum::DEPROVISIONING,
        ]);

        $this->expectException(\RuntimeException::class);

        try {
            (new DeleteVirtualMachineJob($server))->handle(app(CloudManager::class));
        } finally {
            $this->assertSame(ServerStatusEnum::DEPROVISIONING, $server->fresh()->status);
        }
    }

    /**
     * "Placeable" is per location, so a shared cache key served the old location's sizes
     * for an hour and the provision answered 422 unsupported location for server type.
     */
    public function test_the_size_catalogue_is_cached_per_location(): void
    {
        Http::fake([
            'api.hetzner.cloud/v1/server_types*' => Http::response(['server_types' => [
                ['id' => 1, 'name' => 'cx23', 'cores' => 2, 'memory' => 4, 'architecture' => 'x86',
                    'cpu_type' => 'shared', 'prices' => [['price_hourly' => ['gross' => '0.0096']]]],
                ['id' => 2, 'name' => 'ccx23', 'cores' => 4, 'memory' => 16, 'architecture' => 'x86',
                    'cpu_type' => 'dedicated', 'prices' => [['price_hourly' => ['gross' => '0.0490']]]],
            ]]),
            'api.hetzner.cloud/v1/datacenters*' => Http::response(['datacenters' => [
                ['name' => 'nbg1-dc3', 'location' => ['name' => 'nbg1'], 'server_types' => ['available' => [1]]],
                ['name' => 'hel1-dc2', 'location' => ['name' => 'hel1'], 'server_types' => ['available' => [2]]],
            ]]),
        ]);

        Config::set('stream.server.location', 'nbg1');

        $nbg = app(CloudManager::class)->driver('hetzner')->sizes();

        $this->assertArrayHasKey('cx23', $nbg);
        $this->assertArrayNotHasKey('ccx23', $nbg);

        Config::set('stream.server.location', 'hel1');

        // A shared key would still answer nbg1's list here, and provisioning would get
        // 422 unsupported location for server type.
        $hel = app(CloudManager::class)->driver('hetzner')->sizes();

        $this->assertArrayHasKey('ccx23', $hel);
        $this->assertArrayNotHasKey('cx23', $hel);
    }

    /**
     * Writing `manual:{id}` into the old column poisons the provider backfill, which
     * reads a non-empty value as "this is cloud".
     */
    public function test_a_manual_server_does_not_get_a_legacy_provider_id(): void
    {
        Bus::fake();
        Http::fake();

        Config::set('stream.server.provider', 'manual');

        $server = Server::factory()->create([
            'provider' => 'manual',
            'hostname' => 'edge-byo.example.org',
            'ip' => '198.51.100.4',
        ]);

        (new CreateVirtualMachineJob($server))->handle(app(CloudManager::class));

        $server->refresh();

        $this->assertSame('manual:'.$server->id, $server->external_id);
        $this->assertNull($server->hetzner_id);
        $this->assertFalse($server->isCloud());
    }

    /**
     * Succeeding with nothing recorded sent a row with no address on to the DNS write.
     */
    public function test_the_poll_fails_when_nothing_recorded_the_machine(): void
    {
        $server = Server::factory()->create(['provider' => 'hetzner', 'external_id' => null, 'hetzner_id' => null]);

        $this->expectException(\RuntimeException::class);

        (new AwaitPublicAddressJob($server))->handle(app(CloudManager::class));
    }

    public function test_the_poll_fails_when_the_machine_has_no_address(): void
    {
        Config::set('stream.server.provider', 'manual');

        $server = Server::factory()->create(['provider' => 'manual', 'external_id' => 'manual:1', 'ip' => null]);

        $this->expectException(\RuntimeException::class);

        (new AwaitPublicAddressJob($server))->handle(app(CloudManager::class));
    }

    public function test_the_panel_asks_for_an_address_when_the_provider_builds_nothing(): void
    {
        Config::set('stream.server.provider', 'manual');

        $this->actingAs($this->admin)
            ->get(route('manage.servers.index'))
            ->assertInertia(function ($page) {
                $provision = collect($page->toArray()['props']['table']['pageActions'] ?? [])
                    ->firstWhere('name', 'provision');

                $this->assertNotNull($provision);
                $this->assertSame(
                    ['type', 'hostname', 'ip'],
                    collect($provision['fields'])->pluck('key')->all(),
                );
            });
    }

    /**
     * A provider with no catalogue cannot pass a size rule built from one, so the rule
     * is skipped rather than failing against an empty list.
     */
    public function test_registering_a_manual_server_needs_no_size(): void
    {
        Bus::fake();

        Config::set('stream.server.provider', 'manual');

        $this->actingAs($this->admin)
            ->from(route('manage.servers.index'))
            ->post(route('manage.servers.provision'), [
                'type' => ServerTypeEnum::EDGE->value,
                'hostname' => 'edge-byo.stream.example.org',
                'ip' => '198.51.100.4',
            ])
            ->assertSessionHasNoErrors();

        $server = Server::where('hostname', 'edge-byo.stream.example.org')->sole();

        $this->assertSame('manual', $server->provider);
        $this->assertNull($server->server_type);

        Bus::assertDispatched(CreateVirtualMachineJob::class);
    }

    /**
     * The drivers refuse a hostname that is not a hostname, but the form used to accept
     * any string up to 255 characters - and an out-of-zone name is then written by the
     * API drivers as a mangled label inside our own zone rather than being refused.
     */
    public function test_registering_a_manual_server_refuses_a_name_outside_the_zone(): void
    {
        Config::set('stream.server.provider', 'manual');

        $this->actingAs($this->admin)
            ->from(route('manage.servers.index'))
            ->post(route('manage.servers.provision'), [
                'type' => ServerTypeEnum::EDGE->value,
                'hostname' => 'edge-byo.someone-elses.org',
                'ip' => '198.51.100.4',
            ])
            ->assertSessionHasErrors('hostname');

        $this->assertSame(0, Server::where('hostname', 'edge-byo.someone-elses.org')->count());
    }

    public function test_registering_a_manual_server_refuses_a_name_that_is_not_a_hostname(): void
    {
        Config::set('stream.server.provider', 'manual');

        $this->actingAs($this->admin)
            ->from(route('manage.servers.index'))
            ->post(route('manage.servers.provision'), [
                'type' => ServerTypeEnum::EDGE->value,
                'hostname' => '$(id).stream.example.org',
                'ip' => '198.51.100.4',
            ])
            ->assertSessionHasErrors('hostname');
    }

    /**
     * The poll runs for nearly two minutes. An operator who deprovisions inside that
     * window had the late failure flip DELETED back to ERROR, after which the row sat in
     * the dashboard's alert list for good with no action offered on it.
     */
    public function test_a_late_poll_failure_does_not_resurrect_a_torn_down_row(): void
    {
        $server = Server::factory()->create([
            'provider' => 'hetzner',
            'external_id' => '5',
            'status' => ServerStatusEnum::DELETED,
        ]);

        (new AwaitPublicAddressJob($server))->failed(new \Exception('out of attempts'));

        $this->assertSame(ServerStatusEnum::DELETED, $server->fresh()->status);
        $this->assertNull($server->fresh()->health_check_message);
    }

    public function test_registering_a_manual_server_requires_an_address(): void
    {
        Config::set('stream.server.provider', 'manual');

        $this->actingAs($this->admin)
            ->from(route('manage.servers.index'))
            ->post(route('manage.servers.provision'), ['type' => ServerTypeEnum::EDGE->value])
            ->assertSessionHasErrors(['hostname', 'ip']);
    }
}
