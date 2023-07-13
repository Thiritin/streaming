<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\HookRequest;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

class StreamController extends Controller
{
    public function play(HookRequest $request)
    {
        Cache::set('stream.online', true);
        return ['success' => true];
    }
    public function stop(HookRequest $request)
    {
        Cache::set('stream.online', false);
        return ['success' => true];
    }
}
