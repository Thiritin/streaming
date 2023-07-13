<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;

class FrontChannelLogoutController extends Controller
{
    public function __invoke(Request $request)
    {
        \Auth::logout();

        $idToken = Session::get('access_token')?->getValues()['id_token'];
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect("https://identity.eurofurence.org/oauth2/sessions/logout?id_token_hint={$idToken}");
    }
}
