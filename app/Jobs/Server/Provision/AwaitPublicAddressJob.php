<?php

namespace App\Jobs\Server\Provision;

use App\Enum\ServerStatusEnum;
use App\Models\Server;
use App\Services\Cloud\CloudManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Wait for the machine to have an address, then write it to the row.
 *
 * This used to be a `12 x sleep(10)` loop inside CreateVirtualMachineJob, which held a
 * queue worker for up to two minutes per provision - and provisioning several edges at
 * once is exactly when the queue is busiest. Retries do the waiting now, so a worker is
 * free between attempts.
 *
 * A driver with nothing to poll answers Running straight away, which makes this one
 * pass and no API call at all.
 */
class AwaitPublicAddressJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 12;

    public int $backoff = 10;

    public function __construct(public readonly Server $server) {}

    public function handle(CloudManager $cloud): void
    {
        $externalId = $this->server->externalId();

        // Nothing recorded the machine, so there is nothing to poll and no way to find
        // it later. Succeeding here sent a row with no address on to the DNS write.
        if ($externalId === null) {
            throw new \RuntimeException('The provider recorded no id for this server.');
        }

        // The driver named on the row, not the one selected now.
        $status = $cloud->forServer($this->server->cloudProvider())->status($externalId);

        if ($status->ip === null && ! $status->isRunning()) {
            throw new \Exception('The server has no public address yet');
        }

        $this->server->update(array_filter([
            'ip' => $status->ip,
            'internal_ip' => $status->internalIp,
        ]));

        // A driver with nothing to poll answers Running immediately, so a bring-your-own
        // row that was never given an address gets this far with none. The record it
        // would write next has no value to put in it.
        if (blank($this->server->fresh()?->ip)) {
            throw new \RuntimeException('The server has no address.');
        }
    }

    /**
     * Out of attempts means a machine that exists, is billing, and will never get a DNS
     * record or a readiness check because the chain stopped here.
     *
     * The row has to say so, because nothing else would: the dashboard's staleness check
     * only looks at ACTIVE servers, so a row left at PROVISIONING is invisible. ERROR is
     * already an alert with `health_check_message` as its detail, which is also what
     * SendHealthAlertsJob posts.
     */
    public function failed(\Throwable $e): void
    {
        // Only while the row is still the one this job was provisioning. The poll runs
        // for nearly two minutes, and an operator who deprovisions inside that window
        // had the late failure flip DELETED back to ERROR - after which the row sat in
        // the dashboard's alert list for good with no action offered on it.
        if ($this->server->fresh()?->status !== ServerStatusEnum::PROVISIONING) {
            return;
        }

        $this->server->update([
            'status' => ServerStatusEnum::ERROR,
            'health_check_message' => 'The machine never reported a public address. It may still be running at the provider.',
        ]);
    }
}
