<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\HookRequest;
use Illuminate\Support\Facades\Cache;

class StreamController extends Controller
{
    public function play(HookRequest $request)
    {
        Cache::set('stream.online', true);
    }
    public function stop(HookRequest $request)
    {
        Cache::set('stream.online', false);
    }
}
