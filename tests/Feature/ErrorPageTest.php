<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class ErrorPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_missing_page_renders_the_branded_error_page(): void
    {
        $response = $this->get('/this-route-does-not-exist');

        $response->assertNotFound();
        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('ErrorPage')
            ->where('status', 404)
            ->has('branding')
        );
    }

    public function test_a_forbidden_page_renders_the_branded_error_page(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('manage.home'))
            ->assertForbidden()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('ErrorPage')
                ->where('status', 403)
            );
    }

    public function test_the_manage_panel_is_a_404_for_a_guest(): void
    {
        $this->get(route('manage.home'))
            ->assertNotFound()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('ErrorPage')
                ->where('status', 404)
            );
    }

    public function test_json_callers_still_get_json(): void
    {
        $this->getJson('/this-route-does-not-exist')
            ->assertNotFound()
            ->assertJsonStructure(['message']);
    }
}
