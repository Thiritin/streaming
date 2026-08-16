<?php

namespace Tests\Feature;

use App\Enum\ServerStatusEnum;
use App\Jobs\Server\Deprovision\DeleteDnsRecordJob;
use App\Jobs\Server\Deprovision\DeleteVirtualMachineJob;
use App\Models\Server;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
    use RefreshDatabase;

    /**
     * `DeleteDnsRecordJob` returned early for origins while `CreateDnsRecordJob` made a
     * record for them regardless, so every origin teardown leaked its hostname.
     *
     * The cost is not untidiness. `origin-1` was torn down in September 2025 and still
     * resolved to 91.99.165.14 eleven months later - an address Hetzner had long since
     * handed to another customer. A name inside our zone pointing at a stranger's server
     * is a subdomain takeover waiting to be noticed.
     *
     * Asserted structurally rather than by running nsupdate: the job shells out, so the
     * thing worth pinning is that origins are not skipped.
     */
    public function test_the_dns_delete_job_does_not_skip_origins(): void
    {
        $source = file_get_contents(app_path('Jobs/Server/Deprovision/DeleteDnsRecordJob.php'));

        $this->assertStringNotContainsString(
            'ServerTypeEnum::ORIGIN',
            $source,
            'DeleteDnsRecordJob must not special-case origins. CreateDnsRecordJob creates '
            .'a record for them, so skipping the delete leaks the hostname forever.'
        );
    }

    /**
     * The job is a no-op without a Hetzner id, which is the one branch reachable without
     * talking to the API. Included so the guard itself cannot regress into an exception.
     */
    public function test_a_server_with_no_hetzner_id_is_left_alone(): void
    {
        $server = Server::factory()->create([
            'hetzner_id' => null,
            'status' => ServerStatusEnum::DEPROVISIONING,
        ]);

        (new DeleteVirtualMachineJob($server))->handle();

        // Manually managed servers are not ours to mark deleted.
        $this->assertSame(ServerStatusEnum::DEPROVISIONING, $server->fresh()->status);
    }

    /**
     * A VM that is already gone is the state the job exists to reach.
     *
     * `getById()` throws on 404, so an operator deleting a server in the Hetzner console
     * left the row in `deprovisioning` forever with a failed job behind it. That happened
     * twice in September 2025 and again this month. 404 now completes the teardown;
     * anything else still throws.
     */
    public function test_an_already_deleted_vm_completes_the_teardown(): void
    {
        $source = file_get_contents(app_path('Jobs/Server/Deprovision/DeleteVirtualMachineJob.php'));

        $this->assertStringContainsString('ClientException', $source);
        $this->assertStringContainsString('404', $source);
        $this->assertStringContainsString('ServerStatusEnum::DELETED', $source);
    }

    public function test_the_dns_job_is_still_a_no_op_locally(): void
    {
        // Local development has no zone to update, and shelling out to nsupdate there
        // would fail on every teardown.
        $source = file_get_contents(app_path('Jobs/Server/Deprovision/DeleteDnsRecordJob.php'));

        $this->assertStringContainsString('isLocal()', $source);
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
