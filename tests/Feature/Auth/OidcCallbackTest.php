<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class OidcCallbackTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The provider can reject an authorize request for reasons that have nothing to do with
     * this app (a replayed flow, a rotated CSRF cookie on its side, an expired flow). The
     * callback used to answer that by redirecting to auth.login, which immediately starts
     * another authorize round: a loop, with the error invisible.
     */
    public function test_a_provider_error_lands_on_the_sign_in_screen_and_does_not_restart_the_flow(): void
    {
        $response = $this->get('/auth/callback?'.http_build_query([
            'error' => 'request_forbidden',
            'error_description' => 'The request is not allowed. The CSRF value from the token does not match the CSRF value from the data store.',
            'state' => 'aee18cb5e74a21ccb4af997f5b3a1f09',
        ]));

        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors('oidc');

        $this->assertNotSame(route('auth.login'), $response->headers->get('Location'));
    }

    public function test_the_providers_own_error_description_is_not_shown_to_the_user(): void
    {
        $this->get('/auth/callback?'.http_build_query([
            'error' => 'request_forbidden',
            'error_description' => 'The CSRF value from the token does not match the CSRF value from the data store.',
            'state' => 'abc',
        ]));

        $this->assertStringNotContainsString(
            'CSRF',
            session('errors')->get('oidc')[0],
        );
    }

    public function test_a_state_mismatch_lands_on_the_sign_in_screen(): void
    {
        Session::put('login.oauth2state', 'the-state-we-issued');

        $response = $this->get('/auth/callback?'.http_build_query([
            'state' => 'a-different-state',
            'code' => 'some-code',
        ]));

        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors('oidc');
        $this->assertNull(session('login.oauth2state'));
    }

    public function test_a_callback_with_no_state_at_all_does_not_pass_verification(): void
    {
        $response = $this->get('/auth/callback?code=some-code');

        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors('oidc');
    }

    /**
     * Ory Hydra reports an http callback URL as `invalid_request: Redirect URL is using an
     * insecure protocol ...`, but only after a full round trip, which reads like a login
     * failure instead of a misconfigured APP_URL.
     */
    public function test_an_http_callback_url_on_a_non_localhost_host_fails_before_leaving_the_app(): void
    {
        config(['app.url' => 'http://streaming.test']);
        URL::forceRootUrl('http://streaming.test');

        $this->get(route('auth.login'))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('oidc');

        $this->assertStringContainsString(
            'only accepts for localhost hosts',
            session('errors')->get('oidc')[0],
        );
    }

    public function test_an_http_callback_url_on_a_localhost_host_is_accepted(): void
    {
        URL::forceRootUrl('http://streaming.localhost');

        // Reaches the provider setup instead of the guard, so it no longer redirects to the
        // sign-in screen with an error.
        $this->get(route('auth.login'))->assertSessionDoesntHaveErrors('oidc');
    }

    public function test_the_sign_in_screen_renders_the_error(): void
    {
        $this->get('/auth/callback?'.http_build_query([
            'error' => 'request_forbidden',
            'error_description' => 'nope',
            'state' => 'abc',
        ]));

        $this->get(route('login'))
            ->assertSuccessful()
            ->assertSee('Sign-in was refused', false);
    }
}
