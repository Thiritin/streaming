<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\BrandingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;

class FrontChannelLogoutController extends Controller
{
    public function __invoke(Request $request, BrandingService $branding)
    {
        \Auth::logout();

        $idToken = Session::get('access_token')?->getValues()['id_token'];
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        // The provider endpoint is per installation. Without one configured the
        // local session is already gone, so there is nowhere else to send them.
        $logoutUrl = trim((string) $branding->get('identity_logout_url'));

        if ($logoutUrl === '') {
            return redirect('/');
        }

        $separator = str_contains($logoutUrl, '?') ? '&' : '?';

        return redirect($logoutUrl.$separator.'id_token_hint='.urlencode((string) $idToken));
    }
}
