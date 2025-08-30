<?php

namespace App\Jobs\Server\Provision;

use App\Enum\ServerTypeEnum;
use App\Models\Server;
use App\Services\DnsKeyService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CreateDnsRecordJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    public int $tries = 1;

    public function __construct(public readonly Server $server)
    {
    }

    public function handle(): void
    {
        if($this->server->type === ServerTypeEnum::ORIGIN) {
            return;
        }

        $hostname = $this->server->hostname;
        $ip = $this->server->ip;
        $ttl = config('dns.ttl', 60);

        try {
            $dnsService = new DnsKeyService();
            
            $commands = sprintf(
                "update add %s %d A %s",
                $hostname,
                $ttl,
                $ip
            );

            $result = $dnsService->executeNsupdate($commands);
            
            Log::info('DNS record created', [
                'hostname' => $hostname,
                'ip' => $ip,
                'result' => $result
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to create DNS record', [
                'hostname' => $hostname,
                'ip' => $ip,
                'error' => $e->getMessage()
            ]);
            
            throw $e;
        }
    }
}
