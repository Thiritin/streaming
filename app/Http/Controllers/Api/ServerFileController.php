<?php

namespace App\Http\Controllers\Api;

use App\Enum\ServerStatusEnum;
use App\Enum\ServerTypeEnum;
use App\Http\Controllers\Controller;
use App\Models\Server;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Blade;

class ServerFileController extends Controller
{
    public function __invoke(Request $request, $file)
    {
        $server = Server::firstWhere('shared_secret', $request->get('shared_secret'));

        // Set octane https to true via laravel config
        config(['octane.https' => true]);

        if ($file === "docker-compose.yml") {
            return view('docker-compose-yml',[
                'isEdge' => $server->type === ServerTypeEnum::EDGE
            ]);
        }
        if ($file === "Caddyfile") {
            return view('Caddyfile', [
                'hostname' => $server->hostname,
                'username' => config('services.srs.username'),
                'password' => bcrypt(config('services.srs.password')),
            ]);
        }
        if ($file === "edge.conf" && $server->type === ServerTypeEnum::EDGE) {
            $origin = Server::where('type', ServerTypeEnum::ORIGIN)->where('status', ServerStatusEnum::ACTIVE)->firstOrFail();
            return view('edge-conf', [
                'maxConnections' => $server->max_clients * 3,
                'serverId' => $server->id,
                'sharedSecret' => $server->shared_secret,
                'origin' => $origin->internal_ip
            ]);
        }

        if ($file === "origin.conf" && $server->type === ServerTypeEnum::ORIGIN) {
            return view('origin-conf', [
                'maxConnections' => $server->max_clients * 3,
                'serverId' => $server->id,
                'sharedSecret' => $server->shared_secret
            ]);
        }

        if ($file === "cloudinit.sh") {
            return view('cloudinit', [
                'serverUrl' => config('app.url'),
                'sharedSecret' => $server->shared_secret,
            ]);
        }
    }
}
