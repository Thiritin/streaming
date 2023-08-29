<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Inertia\Inertia;

class NoValidTicketController extends Controller
{
    public function __invoke()
    {
        if (\Auth::user()->hasPermissionTo('stream.view')) {
            return redirect()->route('dashboard');
        }
        return Inertia::render('NoTicket');
    }
}
