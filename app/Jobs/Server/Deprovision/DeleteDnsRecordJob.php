<?php

namespace App\Jobs\Server\Deprovision;

use App\Enum\ServerTypeEnum;
use App\Models\Server;
use App\Services\DnsKeyService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class DeleteDnsRecordJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public readonly Server $server) {}

    public function handle(): void
    {
        if (\App::isLocal()) {
            return;
        }

        if ($this->server->type === ServerTypeEnum::ORIGIN) {
            return;
        }

        $hostname = $this->server->hostname;
        $ttl = config('dns.ttl', 60);

        try {
            $dnsService = new DnsKeyService;

            $commands = sprintf(
                'update delete %s %d A',
                $hostname,
                $ttl
            );

            $result = $dnsService->executeNsupdate($commands);

            Log::info('DNS record deleted', [
                'hostname' => $hostname,
                'result' => $result,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to delete DNS record', [
                'hostname' => $hostname,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
