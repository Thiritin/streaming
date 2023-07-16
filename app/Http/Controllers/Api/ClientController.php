<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\HookRequest;
use App\Models\Client;
use App\Models\ServerUser;
use App\Models\User;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class ClientController extends Controller
{
    public function play(HookRequest $request)
    {
        parse_str(Str::substr($request->get('param'), 1), $result);

        if (!isset($result['streamkey'])) {
            return new Response(422, 422);
        }

        $serverUser = ServerUser::where('streamkey', $result['streamkey'])->first();

        if (is_null($serverUser)) {
            return new Response(403, 403);
        }

        // If client id is already set kickoff client
        if (!is_null($serverUser->client_id)) {
            Http::withBasicAuth(config('services.srs.username'), config('services.srs.password'))->delete("https://" . $serverUser->server->hostname . '/api/v1/clients/' . $serverUser->client_id);
        }

        if (isset($result['client'])) {
            $client = ($result['client'] === "vlc") ? 'vlc' : 'web';
        }

        $serverUser->update(['server_id' => $request->get('server_id')]);

        $serverUser->clients()->create([
            "client" => $client ?? 'web',
            "client_id" => $request->get('client_id'),
            "start" => now(),
        ]);

        $serverUser->update([
            'start' => now(),
            'server_id' => $request->get('server_id'),
        ]);

        return new Response(0, 200);
    }

    public function stop(HookRequest $request)
    {
        parse_str(Str::substr($request->get('param'), 1), $result);
        if (!isset($result['streamkey'])) {
            return new Response(422, 422);
        }
        $serverUser = ServerUser::where('streamkey', $result['streamkey'])->first();
        if (is_null($serverUser)) {
            return new Response(403, 403);
        }

        // This indicated the client has switched his browser window.
        // We do not want to invalidate his streaming key, but we are kicking his old session off.
        if ($request->get('client_id') !== $serverUser->client_id) {
            return new Response(0, 200);
        }

        $streamKey = $result['streamkey'];
        Client::where('client_id', $request->get('client_id'))
            ->whereHas('serverUser', fn($q) => $q->where('streamkey', $streamKey))
            ->update(['stop' => now()]);

        return new Response(0, 200);
    }
}
