<?php

namespace App\Jobs\Server\Deprovision;

use App\Enum\ServerStatusEnum;
use App\Models\Server;
use App\Services\Hetzner;
use GuzzleHttp\Exception\ClientException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class DeleteVirtualMachineJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(private readonly Server $server) {}

    public function handle(): void
    {
        if (empty($this->server->hetzner_id)) {
            return;
        }

        $client = Hetzner::client();

        try {
            $client->servers()->getById($this->server->hetzner_id)->delete();
        } catch (ClientException $e) {
            // A VM that is already gone is the state this job exists to reach, so a 404
            // is success, not failure. Treating it as an error left the row stuck in
            // `deprovisioning` forever with a failed job behind it - which is exactly
            // what happened twice in September 2025, and again when an operator deleted
            // a server by hand in the Hetzner console. Anything else still throws.
            if ($e->getResponse()?->getStatusCode() !== 404) {
                throw $e;
            }

            Log::info('Hetzner server was already gone; treating deprovision as complete', [
                'server_id' => $this->server->id,
                'hetzner_id' => $this->server->hetzner_id,
            ]);
        }

        $this->server->update([
            'status' => ServerStatusEnum::DELETED,
        ]);
    }
}
