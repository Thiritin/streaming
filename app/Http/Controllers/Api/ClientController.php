<?php

namespace App\Http\Controllers\Api;

use App\Enum\StreamStatusEnum;
use App\Events\ClientPlayEvent;
use App\Http\Controllers\Controller;
use App\Http\Requests\HookRequest;
use App\Models\Client;
use App\Models\ServerUser;
use App\Models\User;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class ClientController extends Controller
{
    public function play(HookRequest $request)
    {
        $cacheStatus = StreamStatusEnum::tryFrom(Cache::get('stream.status', static fn() => StreamStatusEnum::OFFLINE->value));

        if ($cacheStatus === StreamStatusEnum::OFFLINE) {
            return new Response("Stream is offline.", 403);
        }

        if ($cacheStatus === StreamStatusEnum::STARTING_SOON) {
            return new Response("Stream has not yet started.", 403);
        }

        parse_str(Str::substr($request->get('param'), 1), $result);

        if (!isset($result['streamkey']) || !isset($result['client_id'])) {
            return new Response("Missing streamkey or client id", 422);
        }

        $serverUser = ServerUser::where('streamkey', $result['streamkey'])->first();

        if (is_null($serverUser)) {
            return new Response("No assigned server found by streamkey", 403);
        }

        if (isset($result['client'])) {
            $client = ($result['client'] === "vlc") ? 'vlc' : 'web';
        }

        $serverUser->update([
            'start' => now()
        ]);

        $updatedId = $serverUser->clients()->where('id',$result['client_id'])->update([
            "client" => $client ?? 'web',
            "client_id" => $request->get('client_id'),
            "start" => now(),
        ]);

        if ($updatedId === 0) {
            return new Response("No client found that could be updated", 400);
        }

        ClientPlayEvent::dispatch($result['client_id']);

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

        Client::where('client_id', $request->get('client_id'))
            ->where('server_user_id', $serverUser->id)
            ->update(['stop' => now()]);

        return new Response(0, 200);
    }
}
