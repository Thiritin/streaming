<?php

namespace App\Http\Controllers;

use Inertia\Inertia;

class StreamController extends Controller
{
    public function __invoke()
    {
        return Inertia::render('Dashboard');
    }
}
