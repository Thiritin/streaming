<?php

namespace App\Jobs\Server\Provision;

use App\Enum\ServerStatusEnum;
use App\Enum\ServerTypeEnum;
use App\Models\Server;
use App\Services\Hetzner;
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

    public function __construct(public readonly Server $server)
    {
    }

    public function handle(): void
    {
        $hetznerServerType = ($this->server->type === ServerTypeEnum::ORIGIN) ? "ccx32" : "cx21";
        $hetznerClient = Hetzner::client();
        $name = $this->server->type->value . '-' . $this->server->id . '-' . Str::random(12);
        $server = $hetznerClient->servers()->createInLocation(
            name: $name,
            serverType: $hetznerClient->serverTypes()->getByName($hetznerServerType),
            image: $hetznerClient->images()->getByName('ubuntu-22.04'),
            location: $hetznerClient->locations()->getByName('nbg1'),
            ssh_keys: [$hetznerClient->sshKeys()->getByName('Martin Becker 2023')->id],
            user_data: view('cloudinit', [
                'serverUrl' => config('app.url'),
                'sharedSecret' => $this->server->shared_secret,
                'type' => $this->server->type->value,
            ])->render(),
            networks: [$hetznerClient->networks()->getByName('stream')->id],
            labels: [
                'type' => $this->server->type->value,
            ],
        );

        $server = $server->getResponsePart('server');

        while (!isset($server->private_net[0]->ip)) {
            sleep(10);
            $server = $hetznerClient->servers()->getById($server->id);
        }

        $serverModel = $this->server->update([
            'hetzner_id' => $server->id,
            'hostname' => $name . ".stream.eurofurence.org",
            'ip' => $server->public_net->ipv4->ip,
            'internal_ip' => $server->private_net[0]->ip,
            'max_clients' => 100,
            'status' => ServerStatusEnum::PROVISIONING,
        ]);
    }
}
