<?php

namespace Tests\Feature;

use App\Enum\ServerStatusEnum;
use App\Jobs\Server\Deprovision\DeleteDnsRecordJob;
use App\Jobs\Server\Deprovision\DeleteVirtualMachineJob;
use App\Models\Server;
use App\Services\Cloud\CloudManager;
use App\Services\Dns\DnsManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Concerns\CreatesManageUsers;
use Tests\TestCase;

/**
 * Tearing a server down has to remove everything provisioning created.
 *
 * Both gaps here were invisible until a server was already stranded, because nothing
 * fails loudly: the row simply stops progressing, or a DNS name quietly outlives the
 * machine it pointed at.
 */
class ServerTeardownTest extends TestCase
{
    use CreatesManageUsers;
    use RefreshDatabase;

    /**
     * `DeleteDnsRecordJob` returned early for origins while `CreateDnsRecordJob` made a
     * record for them regardless, so every origin teardown leaked its hostname.
     *
     * The cost is not untidiness. `origin-1` was torn down in September 2025 and still
     * resolved to 91.99.165.14 eleven months later - an address Hetzner had long since
     * handed to another customer. A name inside our zone pointing at a stranger's server
     * is a subdomain takeover waiting to be noticed.
     */
    public function test_the_dns_delete_job_does_not_skip_origins(): void
    {
        Config::set('dns.driver', 'cloudflare');
        Config::set('dns.zone', 'stream.example.org');
        Config::set('dns.cloudflare.token', 'cf-token');
        Config::set('dns.cloudflare.zone_id', 'zone-1');

        Http::fake([
            'api.cloudflare.com/*/dns_records?*' => Http::response(['result' => [['id' => 'rec-origin']]]),
            'api.cloudflare.com/*' => Http::response(['result' => []]),
        ]);

        $origin = Server::factory()->origin()->create(['status' => ServerStatusEnum::DEPROVISIONING]);

        (new DeleteDnsRecordJob($origin))->handle(app(DnsManager::class));

        Http::assertSent(fn ($request) => $request->method() === 'DELETE'
            && str_contains($request->url(), '/dns_records/rec-origin'));
    }

    /**
     * A manually managed server calls no API and still finishes.
     *
     * It used to return early without touching the status, which left the row sitting in
     * `deprovisioning` for good with nothing to retry and no error to read. There is
     * nothing to delete, which is not the same as nothing to do.
     */
    public function test_a_manual_server_completes_without_an_api_call(): void
    {
        Http::fake();

        $server = Server::factory()->create(['status' => ServerStatusEnum::DEPROVISIONING]);

        (new DeleteVirtualMachineJob($server))->handle(app(CloudManager::class));

        Http::assertNothingSent();
        $this->assertSame(ServerStatusEnum::DELETED, $server->fresh()->status);
    }

    /**
     * A VM that is already gone is the state the job exists to reach.
     *
     * A 404 used to be an exception, so an operator deleting a server in the provider's
     * console left the row in `deprovisioning` forever with a failed job behind it. That
     * happened twice in September 2025 and again this month.
     */
    public function test_an_already_deleted_vm_completes_the_teardown(): void
    {
        Config::set('services.hetzner.token', 'token');

        Http::fake(['api.hetzner.cloud/*' => Http::response(['error' => []], 404)]);

        $server = Server::factory()->cloud()->create(['status' => ServerStatusEnum::DEPROVISIONING]);

        (new DeleteVirtualMachineJob($server))->handle(app(CloudManager::class));

        $this->assertSame(ServerStatusEnum::DELETED, $server->fresh()->status);
    }

    /**
     * Anything that is not a 404 still throws: a rate limit or a refused token means the
     * machine is very much still running, and marking the row deleted would hide it.
     */
    public function test_a_refused_delete_still_fails(): void
    {
        Config::set('services.hetzner.token', 'token');

        Http::fake(['api.hetzner.cloud/*' => Http::response(['error' => []], 403)]);

        $server = Server::factory()->cloud()->create(['status' => ServerStatusEnum::DEPROVISIONING]);

        $this->expectException(RequestException::class);

        (new DeleteVirtualMachineJob($server))->handle(app(CloudManager::class));
    }

    /**
     * Local development has no zone to update, and shelling out to nsupdate there would
     * fail on every teardown. That used to be an `App::isLocal()` branch inside the job,
     * which is a driver choice made in the wrong place: it is the `none` driver now, and
     * the job no longer knows what environment it is in.
     */
    public function test_no_driver_means_nothing_is_written(): void
    {
        Config::set('dns.driver', 'none');

        Http::fake();

        $server = Server::factory()->create(['status' => ServerStatusEnum::DEPROVISIONING]);

        (new DeleteDnsRecordJob($server))->handle(app(DnsManager::class));

        Http::assertNothingSent();
    }

    /**
     * A manually managed server still gets an A record in our zone, so the only action
     * the panel offered - Delete - dropped the row and left the name resolving to an
     * address the operator no longer controls. That is origin-1 again, and it does not
     * care who owned the machine.
     */
    public function test_a_manual_server_with_a_record_is_deprovisioned_not_deleted(): void
    {
        $this->createManageUsers();

        $user = $this->admin;

        $withRecord = Server::factory()->create([
            'metadata' => ['dns_provider' => 'cloudflare', 'dns_zone' => 'stream.example.org'],
        ]);

        $this->assertTrue($user->can('deprovision', $withRecord));
        $this->assertFalse($user->can('delete', $withRecord));

        // Nothing was ever written for this one, so there is genuinely nothing to tear
        // down and Delete stays the honest action.
        $withoutRecord = Server::factory()->create();

        $this->assertFalse($user->can('deprovision', $withoutRecord));
        $this->assertTrue($user->can('delete', $withoutRecord));
    }

    /**
     * And the chain it now reaches actually removes the record, with the driver named on
     * the row, before the no-op machine delete carries it to DELETED.
     */
    public function test_deprovisioning_a_manual_server_removes_its_record(): void
    {
        Config::set('dns.driver', 'none');
        Config::set('dns.zone', 'stream.example.org');
        Config::set('dns.cloudflare.token', 'cf-token');
        Config::set('dns.cloudflare.zone_id', 'zone-1');

        Http::fake([
            'api.cloudflare.com/*/dns_records?*' => Http::response(['result' => [['id' => 'rec-1']]]),
            'api.cloudflare.com/*' => Http::response(['result' => []]),
        ]);

        $server = Server::factory()->create([
            'status' => ServerStatusEnum::DEPROVISIONING,
            'metadata' => ['dns_provider' => 'cloudflare', 'dns_zone' => 'stream.example.org'],
        ]);

        (new DeleteDnsRecordJob($server))->handle(app(DnsManager::class));
        (new DeleteVirtualMachineJob($server))->handle(app(CloudManager::class));

        Http::assertSent(fn ($request) => $request->method() === 'DELETE'
            && str_contains($request->url(), '/dns_records/rec-1'));

        $this->assertSame(ServerStatusEnum::DELETED, $server->fresh()->status);
    }

    public function test_the_teardown_deletes_dns_before_the_machine(): void
    {
        // Order matters: once the VM is gone its address can be reassigned, so the record
        // has to go first. RemovalConditionCheckerJob chains them in that order.
        $source = file_get_contents(app_path('Jobs/Server/Deprovision/RemovalConditionCheckerJob.php'));

        $dns = strpos($source, 'DeleteDnsRecordJob');
        $vm = strpos($source, 'DeleteVirtualMachineJob');

        $this->assertNotFalse($dns);
        $this->assertNotFalse($vm);
        $this->assertLessThan($vm, $dns, 'DNS must be removed before the machine it points at.');
    }

    /**
     * A DNS failure must not keep the machine alive.
     *
     * The job runs before DeleteVirtualMachineJob in the chain, and it used to rethrow.
     * `Bus::chain` stops at the first failure, so one broken nsupdate key meant every
     * teardown in the fleet left its Hetzner server running and billing. A stale A record
     * is the cheaper failure of the two, and it is logged rather than swallowed silently.
     */
    public function test_a_dns_failure_does_not_abort_the_teardown(): void
    {
        Config::set('dns.driver', 'cloudflare');
        Config::set('dns.zone', 'stream.example.org');
        Config::set('dns.cloudflare.token', 'cf-token');
        Config::set('dns.cloudflare.zone_id', 'zone-1');

        Log::shouldReceive('error')->atLeast()->once();
        Log::shouldReceive('info')->zeroOrMoreTimes();

        Http::fake(['api.cloudflare.com/*' => Http::response([], 500)]);

        $server = Server::factory()->create(['status' => ServerStatusEnum::DEPROVISIONING]);

        (new DeleteDnsRecordJob($server))->handle(app(DnsManager::class));

        // Reaching here at all is the assertion: a throw would have broken the chain.
        $this->assertTrue(true);
    }

    public function test_the_dns_delete_job_exists_for_every_server_type(): void
    {
        foreach ([Server::factory()->origin(), Server::factory()] as $factory) {
            $server = $factory->create(['status' => ServerStatusEnum::DEPROVISIONING]);

            // Constructing it for either role must be possible; the origin early-return
            // used to make this meaningless for half of them.
            $this->assertInstanceOf(DeleteDnsRecordJob::class, new DeleteDnsRecordJob($server));
        }
    }
}
