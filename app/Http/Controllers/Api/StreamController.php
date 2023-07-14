<?php

namespace App\Http\Controllers\Api;

use App\Enum\StreamStatusEnum;
use App\Events\StreamStatusEvent;
use App\Http\Controllers\Controller;
use App\Http\Requests\HookRequest;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class StreamController extends Controller
{
    public function play(HookRequest $request)
    {
        parse_str(Str::substr($request->get('param'),1), $result);
        if (!isset($result['streamkey'])) {
            return new Response(null,419);
        }
        if($result['streamkey'] === config('app.stream_key')) {
            event(new StreamStatusEvent(StreamStatusEnum::ONLINE));
            return new Response(0);
        }
        return new Response(null,403);
    }

    public function stop(HookRequest $request)
    {
        parse_str(Str::substr($request->get('param'),1), $result);
        if (!isset($result['streamkey'])) {
            return new Response(null,419);
        }
        if($result['streamkey'] === config('app.stream_key')) {
            event(new StreamStatusEvent(StreamStatusEnum::TECHNICAL_ISSUE));
            return new Response(0);
        }
        return new Response(null,403);
    }
}
