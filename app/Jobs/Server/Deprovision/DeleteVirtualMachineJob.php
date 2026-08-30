<?php

namespace App\Jobs\Server\Deprovision;

use App\Enum\ServerStatusEnum;
use App\Models\Server;
use App\Services\Cloud\CloudManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DeleteVirtualMachineJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(private readonly Server $server) {}

    public function handle(CloudManager $cloud): void
    {
        $externalId = $this->server->externalId();

        // The driver the machine was built by, never the one selected now. Resolving the
        // current setting instead would mean switching the installation to `manual`
        // orphans every running VM: still billing, invisible to the panel, deletable
        // only by hand in the provider's console.
        $provider = $cloud->forServer($this->server->cloudProvider());

        /*
         * A row that is cloud by its id but whose provider resolved to one that builds
         * nothing - the deploy-window row falling back to an installation since switched
         * to manual. Doing nothing here would mark it DELETED with the machine still
         * running and billing, and nothing would ever look at it again. Failing leaves
         * the row in `deprovisioning` with a failed job behind it, which is a thing
         * somebody finds.
         */
        if ($externalId !== null && $this->server->isCloud() && ! $provider->supportsProvisioning()) {
            throw new \RuntimeException(
                "Server {$this->server->id} was built by a provider this installation no longer has configured. "
                ."Set the cloud provider back, or delete {$externalId} in its console first."
            );
        }

        if ($externalId !== null) {
            // Already gone is success, which is the driver's business rather than this
            // job's: an operator deleting a machine in the provider's console used to
            // leave the row in `deprovisioning` forever with a failed job behind it.
            $provider->delete($externalId);
        }

        // Reached whether or not there was anything to delete. A manually managed server
        // used to return early here, which left its row in `deprovisioning` for good.
        $this->server->update([
            'status' => ServerStatusEnum::DELETED,
        ]);
    }
}
