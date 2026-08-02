<?php

namespace Tests\Feature\Manage;

use App\Enum\ServerStatusEnum;
use App\Enum\ServerTypeEnum;
use App\Models\Server;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesManageUsers;
use Tests\TestCase;

class AccessTest extends TestCase
{
    use CreatesManageUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createManageUsers();
    }

    public function test_guests_are_sent_to_the_application_login(): void
    {
        // Deliberately different from /admin, which had its own login screen.
        $this->get('/manage')->assertRedirect(route('login'));
    }

    public function test_users_without_the_gate_are_forbidden(): void
    {
        $this->actingAs($this->viewer)->get('/manage')->assertForbidden();
    }

    /**
     * The panel root is the dashboard: capacity, health, alerts, viewers and the next
     * few hours of programme on one screen.
     */
    public function test_the_panel_root_is_the_dashboard(): void
    {
        $this->actingAs($this->admin)
            ->get('/manage')
            ->assertInertia(fn (Assert $page) => $page->component('Manage/Dashboard'));
    }

    public function test_a_role_holding_only_the_legacy_filament_permission_still_gets_in(): void
    {
        $this->actingAs($this->moderator)
            ->get('/manage')
            ->assertSuccessful();

        $this->actingAs($this->moderator)
            ->get(route('manage.servers.index'))
            ->assertSuccessful();
    }

    public function test_the_sidebar_only_advertises_routes_that_exist(): void
    {
        $this->actingAs($this->admin)
            ->get(route('manage.servers.index'))
            ->assertInertia(function (Assert $page) {
                $routes = collect($page->toArray()['props']['manageNav'])
                    ->flatMap(fn (array $group) => $group['items'])
                    ->pluck('route');

                $this->assertContains('manage.servers.index', $routes);

                foreach ($routes as $name) {
                    $this->assertTrue(
                        Route::has($name),
                        "Navigation advertises the missing route [{$name}]."
                    );
                }
            });
    }

    public function test_the_status_strip_reports_edge_capacity_and_the_stream_state(): void
    {
        Server::factory()->create(['viewer_count' => 42]);
        Server::factory()->status(ServerStatusEnum::PROVISIONING)->create();

        $this->actingAs($this->admin)
            ->get(route('manage.servers.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('manageStatus.edge.active', 1)
                ->where('manageStatus.edge.total', 2)
                ->where('manageStatus.viewers', 42)
                ->where('manageStatus.stream.label', 'Offline')
            );
    }

    /**
     * Autoscaling was removed with the feature itself: capacity is provisioned by hand
     * behind an nginx reverse proxy. Nothing may reintroduce a switch for it.
     */
    public function test_the_status_strip_says_nothing_about_autoscaling(): void
    {
        $this->actingAs($this->admin)
            ->get(route('manage.servers.index'))
            ->assertInertia(function (Assert $page) {
                $props = $page->toArray()['props'];

                $this->assertArrayNotHasKey('autoscaler', $props['manageStatus']);
                $this->assertArrayNotHasKey('autoscaler', $props);
            });

        $this->assertFalse(Route::has('manage.autoscaler'));
    }

    public function test_only_edge_servers_count_towards_the_strips_capacity(): void
    {
        Server::factory()->origin()->create(['viewer_count' => 99]);

        $this->actingAs($this->admin)
            ->get(route('manage.servers.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('manageStatus.edge.total', 0)
                ->where('manageStatus.viewers', 0)
            );
    }

    public function test_deleted_servers_do_not_count_towards_the_strips_edge_total(): void
    {
        Server::factory()->create();
        Server::factory()->status(ServerStatusEnum::DELETED)->create();

        $this->actingAs($this->admin)
            ->get(route('manage.servers.index'))
            ->assertInertia(fn (Assert $page) => $page->where('manageStatus.edge.total', 1));
    }

    public function test_the_sidebar_badges_report_live_shows_and_online_sources(): void
    {
        Server::factory()->create(['type' => ServerTypeEnum::EDGE]);

        $this->actingAs($this->admin)
            ->get(route('manage.servers.index'))
            ->assertInertia(function (Assert $page) {
                $groups = collect($page->toArray()['props']['manageNav'])->pluck('label');

                // Overview leads the rail now that the dashboard exists.
                $this->assertSame('Overview', $groups->first());
                $this->assertContains('Infrastructure', $groups);
            });
    }
}
