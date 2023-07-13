<?php

namespace App\Jobs;

use App\Models\Server;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DeprovisionServerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private readonly Server $server)
    {
    }

    public function handle(): void
    {
        // Put Server into deprovisioning
        // Broadcast Server deprovisioning
        // Sleep until client count is 0
        // Delete Server
        // Delete DNS Record
    }
}
