<?php

namespace Tests\Feature\Manage;

use App\Enum\ServerStatusEnum;
use App\Enum\ServerTypeEnum;
use App\Enum\SourceStatusEnum;
use App\Models\Server;
use App\Models\ServerMetric;
use App\Models\Show;
use App\Models\Source;
use App\Support\Manage\Overview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesManageUsers;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use CreatesManageUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createManageUsers();
    }

    public function test_the_dashboard_reports_capacity_viewers_servers_and_schedule(): void
    {
        Server::factory()->create([
            'type' => ServerTypeEnum::EDGE,
            'status' => ServerStatusEnum::ACTIVE,
            'hostname' => 'edge-1',
            'max_clients' => 100,
            'viewer_count' => 40,
            'health_status' => 'healthy',
            'last_heartbeat' => now(),
        ]);

        $this->actingAs($this->admin)
            ->get(route('manage.home'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Manage/Dashboard')
                ->has('capacity')
                ->has('edgeServers')
                ->has('servers', 1)
                ->has('alerts')
                ->has('schedule')
                ->where('viewers.total', 40)
                ->where('servers.0.hostname', 'edge-1')
                ->where('servers.0.load', 40)
                ->where('scheduleHours', 6));
    }

    public function test_a_source_in_error_raises_an_alert(): void
    {
        Source::factory()->create(['name' => 'Main Stage', 'status' => SourceStatusEnum::ERROR]);

        $this->actingAs($this->admin)
            ->get(route('manage.home'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('alerts.0.tone', 'danger')
                ->where('alerts.0.title', "Source 'Main Stage' is in error"));
    }

    public function test_an_unhealthy_server_raises_an_alert(): void
    {
        Server::factory()->create([
            'type' => ServerTypeEnum::EDGE,
            'status' => ServerStatusEnum::ACTIVE,
            'hostname' => 'edge-9',
            'health_status' => 'unhealthy',
            'health_check_message' => 'connection refused',
            'last_heartbeat' => now(),
        ]);

        $this->actingAs($this->admin)
            ->get(route('manage.home'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('alerts.0.tone', 'danger')
                ->where('alerts.0.title', 'Server edge-9 is failing its health check')
                ->where('alerts.0.detail', 'connection refused'));
    }

    public function test_a_stale_heartbeat_raises_a_warning(): void
    {
        Server::factory()->create([
            'type' => ServerTypeEnum::EDGE,
            'status' => ServerStatusEnum::ACTIVE,
            'hostname' => 'edge-quiet',
            'health_status' => 'healthy',
            'last_heartbeat' => now()->subMinutes(10),
        ]);

        $this->actingAs($this->admin)
            ->get(route('manage.home'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('alerts.0.tone', 'warn')
                ->where('alerts.0.title', 'Server edge-quiet has not checked in'));
    }

    /**
     * A refused credential and a crashed box both stop the heartbeat. This one is the
     * more specific answer and the app knows it, so it replaces the stale line rather
     * than sitting beside it - and it carries its own key, so a fix posts a cleared
     * line of its own through HealthAlertDigest.
     */
    public function test_a_rejected_credential_replaces_the_stale_heartbeat_alert(): void
    {
        $server = Server::factory()->create([
            'type' => ServerTypeEnum::EDGE,
            'status' => ServerStatusEnum::ACTIVE,
            'hostname' => 'edge-refused',
            'health_status' => 'healthy',
            'last_heartbeat' => now()->subMinutes(10),
            'credential_rejected_at' => now()->subMinutes(9),
        ]);

        $this->actingAs($this->admin)
            ->get(route('manage.home'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('alerts.0.tone', 'danger')
                ->where('alerts.0.title', 'Server edge-refused credentials rejected')
                ->where('alerts.0.key', "server:{$server->id}:credentials"));

        $keys = collect(app(Overview::class)->alerts())->pluck('key');

        $this->assertFalse($keys->contains("server:{$server->id}:stale"));
    }

    /**
     * An origin that fills up stops recording and an edge that fills up stops caching,
     * so the warning has to arrive well before either does.
     */
    public function test_a_nearly_full_disk_raises_an_alert(): void
    {
        $server = Server::factory()->create([
            'type' => ServerTypeEnum::EDGE,
            'status' => ServerStatusEnum::ACTIVE,
            'hostname' => 'edge-full',
            'health_status' => 'healthy',
            'last_heartbeat' => now(),
        ]);

        ServerMetric::create([
            'server_id' => $server->id,
            'recorded_at' => now(),
            'disk_total_bytes' => 100_000_000_000,
            'disk_used_bytes' => 97_000_000_000,
        ]);

        $this->actingAs($this->admin)
            ->get(route('manage.home'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('alerts.0.tone', 'danger')
                ->where('alerts.0.title', 'Server edge-full is running out of disk'));
    }

    public function test_a_healthy_disk_raises_nothing(): void
    {
        $server = Server::factory()->create([
            'status' => ServerStatusEnum::ACTIVE,
            'health_status' => 'healthy',
            'last_heartbeat' => now(),
        ]);

        ServerMetric::create([
            'server_id' => $server->id,
            'recorded_at' => now(),
            'disk_total_bytes' => 100_000_000_000,
            'disk_used_bytes' => 40_000_000_000,
        ]);

        $this->actingAs($this->admin)
            ->get(route('manage.home'))
            ->assertInertia(fn (Assert $page) => $page->count('alerts', 0));
    }

    public function test_a_live_show_on_an_offline_source_raises_an_alert(): void
    {
        $source = Source::factory()->create(['name' => 'Stage B', 'status' => SourceStatusEnum::OFFLINE]);
        Show::factory()->create([
            'source_id' => $source->id,
            'title' => 'Opening Ceremony',
            'status' => 'live',
        ]);

        $this->actingAs($this->admin)
            ->get(route('manage.home'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('alerts.0.tone', 'danger')
                ->where('alerts.0.title', "'Opening Ceremony' is live but its source is not online"));
    }

    /**
     * Danger before warning, so the first line of the list is the thing to fix.
     */
    public function test_alerts_are_ordered_worst_first(): void
    {
        Server::factory()->create([
            'type' => ServerTypeEnum::EDGE,
            'status' => ServerStatusEnum::ACTIVE,
            'hostname' => 'edge-quiet',
            'health_status' => 'healthy',
            'last_heartbeat' => now()->subMinutes(10),
        ]);
        Source::factory()->create(['name' => 'Main Stage', 'status' => SourceStatusEnum::ERROR]);

        $this->actingAs($this->admin)
            ->get(route('manage.home'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('alerts.0.tone', 'danger')
                ->where('alerts.1.tone', 'warn'));
    }

    public function test_the_schedule_covers_live_shows_and_the_next_few_hours_only(): void
    {
        $source = Source::factory()->create(['status' => SourceStatusEnum::ONLINE]);

        $live = Show::factory()->create([
            'source_id' => $source->id,
            'title' => 'On air now',
            'status' => 'live',
            'scheduled_start' => now()->subHour(),
        ]);
        $soon = Show::factory()->create([
            'source_id' => $source->id,
            'title' => 'Up next',
            'status' => 'scheduled',
            'scheduled_start' => now()->addHour(),
        ]);
        Show::factory()->create([
            'source_id' => $source->id,
            'title' => 'Tomorrow',
            'status' => 'scheduled',
            'scheduled_start' => now()->addDay(),
        ]);

        $this->actingAs($this->admin)
            ->get(route('manage.home'))
            ->assertInertia(function (Assert $page) use ($live, $soon) {
                $ids = collect($page->toArray()['props']['schedule'])->pluck('id');

                // Live first, then by start time; tomorrow's show is outside the window.
                $this->assertSame([$live->id, $soon->id], $ids->all());
            });
    }

    public function test_the_dashboard_needs_the_manage_gate(): void
    {
        // The guest redirect is asserted in AccessTest, which starts unauthenticated.
        $this->actingAs($this->viewer)->get(route('manage.home'))->assertForbidden();
    }
}
