<?php

namespace App\Services;

use Cache;
use Http;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use League\OAuth2\Client\Provider\AbstractProvider;
use League\OAuth2\Client\Provider\GenericProvider;

class OpenIDService
{

    public function setupOIDC(Request $request, bool $clientIsAdmin): GenericProvider
    {
        $config = Cache::remember('openid-configuration', now()->addHour(), function () {
            return Http::get(config('services.oidc.url') . "/.well-known/openid-configuration")->json();
        });

        $clientId = config('services.oidc.client_id');
        $clientSecret = config('services.oidc.secret');
        $clientCallback = route('auth.callback');

        return new GenericProvider([
            'clientId' => $clientId,
            'clientSecret' => $clientSecret,
            'redirectUri' => $clientCallback,
            'urlAuthorize' => $config['authorization_endpoint'],
            'urlAccessToken' => $config['token_endpoint'],
            'urlResourceOwnerDetails' => $config['userinfo_endpoint'],
            'accessTokenMethod' => AbstractProvider::METHOD_POST,
            'scopeSeparator' => ' ',
            'scopes' => ['openid', 'profile'],
        ]);
    }
}
