<?php

namespace App\Http\Controllers\Api;

use App\Enum\StreamStatusEnum;
use App\Events\ClientPlayEvent;
use App\Http\Controllers\Controller;
use App\Http\Requests\HookRequest;
use App\Models\User;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class ClientController extends Controller
{
    public function play(HookRequest $request)
    {
        $cacheStatus = StreamStatusEnum::tryFrom(Cache::get('stream.status',
            static fn () => StreamStatusEnum::OFFLINE->value));

        if ($cacheStatus === StreamStatusEnum::OFFLINE) {
            return new Response('Stream is offline.', 403);
        }

        if ($cacheStatus === StreamStatusEnum::STARTING_SOON) {
            return new Response('Stream has not yet started.', 403);
        }

        parse_str(Str::substr($request->get('param'), 1), $result);

        if (! isset($result['streamkey']) || ! isset($result['client_id'])) {
            return new Response('Missing streamkey or client id', 422);
        }

        // This is the streamkey for external apps
        if ($result['streamkey'] === config('services.signage.streamkey')) {
            return new Response(0, 200);
        }

        $user = User::where('streamkey', $result['streamkey'])->first();

        if (is_null($user)) {
            return new Response('No assigned server found by streamkey', 403);
        }

        if ((int) $request->get('server_id') !== $user->server_id && ! \App::isLocal()) {
            return new Response('Server id does not match', 403);
        }

        if (isset($result['client'])) {
            $client = ($result['client'] === 'vlc') ? 'vlc' : 'web';
        }

        $updatedId = $user->clients()->where('id', $result['client_id'])->update([
            'client' => $client ?? 'web',
            'client_id' => $request->get('client_id'),
            'start' => now(),
            'stop' => null,
        ]);

        if ($updatedId === 0) {
            return new Response('No client found that could be updated', 400);
        }

        ClientPlayEvent::dispatch($result['client_id']);

        return new Response(0, 200);
    }

    public function stop(HookRequest $request)
    {
        parse_str(Str::substr($request->get('param'), 1), $result);
        if (! isset($result['streamkey'])) {
            return new Response(422, 422);
        }

        // This is the streamkey for external apps
        if ($result['streamkey'] === config('services.signage.streamkey')) {
            return new Response(0, 200);
        }

        $user = User::where('streamkey', $result['streamkey'])->first();
        if (is_null($user)) {
            return new Response(403, 403);
        }

        $user
            ->clients()
            ->where('client_id', $request->get('client_id'))
            ->update(['stop' => now()]);

        return new Response(0, 200);
    }
}
