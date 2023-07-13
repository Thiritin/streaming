<?php

namespace App\Http\Controllers;

use Inertia\Inertia;

class StreamController extends Controller
{
    public function __invoke()
    {
        return Inertia::render('Dashboard', [
            'personalizedStreamUrl' => "http://127.0.0.1:8080/live/livestream.flv",
            'status' => 'online',
        ]);
    }
}
