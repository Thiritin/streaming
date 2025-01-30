<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Inertia\Inertia;

class NoValidTicketController extends Controller
{
    public function __invoke()
    {
        return Inertia::render('NoTicket');
    }
}
