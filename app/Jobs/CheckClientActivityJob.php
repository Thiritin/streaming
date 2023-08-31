<?php

namespace App\Jobs;

use App\Enum\ServerStatusEnum;
use App\Models\Client;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;

class CheckClientActivityJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
    }

    public function handle(): void
    {
        $clients = Client::whereNull('stop')->whereNotNull('start')->with('serverUser.server')->get();
        $clients
            ->reject(fn(Client $client) => empty($client->client_id))
            ->each(function (Client $client) {
                if ($client->serverUser->server->status === ServerStatusEnum::DELETED) {
                    $client->update(['stop' => now()]);
                    return true;
                }

                // Ask Server if client is still active
                $proto = app()->isLocal() ? "http" : "https";
                $hostname = app()->isLocal() ? "stream:1985" : $client->serverUser->server;
                $request = Http::withBasicAuth(config('services.srs.username'),
                    config('services.srs.password'))->get($proto."://".$hostname.'/api/v1/clients/'.$client->client_id);
                if ($request->json()['code'] !== 0) {
                    $client->update(['stop' => now()]);
                }
            });
    }
}
