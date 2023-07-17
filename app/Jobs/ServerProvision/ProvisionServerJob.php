<?php

namespace App\Jobs\ServerProvision;

use App\Enum\ServerStatusEnum;
use App\Models\Server;
use App\Services\Hetzner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use LKDev\HetznerCloud\APIException;
use LKDev\HetznerCloud\Models\Servers\Types\ServerType;

class ProvisionServerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
    }

    /**
     * @throws APIException
     */
    public function handle(): void
    {
        // Sleep until server is available
        // Put server into available
        // Broadcast new server availability
    }
}
