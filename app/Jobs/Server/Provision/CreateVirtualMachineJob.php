<?php

namespace App\Jobs\Server\Provision;

use App\Enum\ServerStatusEnum;
use App\Enum\ServerTypeEnum;
use App\Models\Server;
use App\Services\Hetzner;
use App\Services\ServerProvisioningService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

class CreateVirtualMachineJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public readonly Server $server) {}

    public function handle(): void
    {
        $hetznerServerType = ($this->server->type === ServerTypeEnum::ORIGIN) ? 'ccx43' : 'cpx21';
        $hetznerClient = Hetzner::client();
        $name = $this->server->type->value.'-'.$this->server->id.'-'.Str::random(12);
        
        // Generate cloud-init script using the provisioning service
        $provisioningService = app(ServerProvisioningService::class);
        $cloudInitScript = $provisioningService->generateCloudInit($this->server);
        
        // Prepare SSH keys array - only add if available
        $sshKeys = [];
        try {
            $sshKey = $hetznerClient->sshKeys()->getByName('Martin Becker 2023');
            if ($sshKey) {
                $sshKeys[] = $sshKey->id;
            }
        } catch (\Exception $e) {
            // SSH key not found, continue without it
            \Log::warning('SSH key "Martin Becker 2023" not found, provisioning without SSH key');
        }

        // Prepare networks array - only add if available
        $networks = [];
        try {
            $network = $hetznerClient->networks()->getByName('stream');
            if ($network) {
                $networks[] = $network->id;
            }
        } catch (\Exception $e) {
            // Network not found, continue without it
            \Log::warning('Network "stream" not found, provisioning without specific network');
        }

        // Build request payload directly due to SDK issues
        $serverType = $hetznerClient->serverTypes()->getByName($hetznerServerType);
        $image = $hetznerClient->images()->getByName('ubuntu-22.04');
        $location = $hetznerClient->locations()->getByName('nbg1');
        
        $payload = [
            'name' => $name,
            'server_type' => $serverType->id,
            'image' => $image->id,
            'location' => $location->id,
            'ssh_keys' => $sshKeys,
            'networks' => $networks,
            'user_data' => $cloudInitScript,
            'start_after_create' => true,
            'labels' => [
                'type' => $this->server->type->value,
            ],
        ];
        
        // Make direct API call using Guzzle
        $httpClient = new \GuzzleHttp\Client();
        $response = $httpClient->post('https://api.hetzner.cloud/v1/servers', [
            'headers' => [
                'Authorization' => 'Bearer ' . config('services.hetzner.token'),
                'Content-Type' => 'application/json',
            ],
            'json' => $payload,
        ]);
        
        $responseBody = json_decode($response->getBody()->getContents());
        $server = $responseBody->server;

        // Wait for server to be ready with network info  
        $maxAttempts = 12; // 2 minutes max wait
        $attempts = 0;
        while ($attempts < $maxAttempts) {
            sleep(10);
            
            // Fetch updated server info
            $getResponse = $httpClient->get("https://api.hetzner.cloud/v1/servers/{$server->id}", [
                'headers' => [
                    'Authorization' => 'Bearer ' . config('services.hetzner.token'),
                ],
            ]);
            $server = json_decode($getResponse->getBody()->getContents())->server;
            
            // Check if we have the public IP (always required)
            if (isset($server->public_net->ipv4->ip)) {
                break;
            }
            $attempts++;
        }

        // Get the internal IP if available (might not exist without private network)
        $internalIp = isset($server->private_net[0]->ip) ? $server->private_net[0]->ip : null;

        $this->server->update([
            'hetzner_id' => $server->id,
            'hostname' => $name.'.stream.eurofurence.org',
            'ip' => $server->public_net->ipv4->ip,
            'internal_ip' => $internalIp,
            'port' => 443,
            'max_clients' => ($this->server->type === ServerTypeEnum::EDGE) ? 100 : 1000,
            'status' => ServerStatusEnum::PROVISIONING,
        ]);
        
        // Chain the DNS creation and wait for ready jobs
        \Illuminate\Support\Facades\Bus::chain([
            new CreateDnsRecordJob($this->server),
            new WaitUntilServerIsReadyJob($this->server),
        ])->dispatch();
    }
}
