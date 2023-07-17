<?php

namespace App\Jobs\Server\Deprovision;

use App\Enum\ServerTypeEnum;
use App\Models\Server;
use App\Services\ShellCommand;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DeleteDnsRecordJob implements ShouldQueue
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

        ShellCommand::execute("
nsupdate -v -k dns.key << EOF
server 85.199.154.53
zone stream.eurofurence.org
update delete $hostname 60 A
send
EOF"
        );
    }
}
