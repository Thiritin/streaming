<?php

namespace Tests\Feature;

use App\Enum\ServerTypeEnum;
use App\Jobs\PruneServerMetricsJob;
use App\Models\Server;
use App\Models\ServerMetric;
use App\Services\ServerMetricsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What a server reports about itself once a minute, and what the server page makes of it.
 */
class ServerMetricsTest extends TestCase
{
    use RefreshDatabase;

    private function heartbeat(Server $server, array $payload, ?string $secret = null)
    {
        return $this->withHeaders(['X-Shared-Secret' => $secret ?? $server->shared_secret])
            ->postJson(route('api.server.heartbeat', $server), $payload);
    }

    private function sample(): array
    {
        return [
            'cpu_percent' => 42.5,
            'cpu_cores' => 4,
            'load_1' => 1.25,
            'memory_used_bytes' => 3_221_225_472,
            'memory_total_bytes' => 8_589_934_592,
            'disk_used_bytes' => 12_884_901_888,
            'disk_total_bytes' => 80_530_636_800,
            'net_rx_bytes_per_sec' => 1_500_000,
            'net_tx_bytes_per_sec' => 45_000_000,
            'uptime_seconds' => 269_100,
        ];
    }

    public function test_a_heartbeat_stores_the_system_sample(): void
    {
        $server = Server::factory()->create(['viewer_count' => 17]);

        $this->heartbeat($server, ['health' => ['local' => 'healthy'], 'metrics' => $this->sample()])
            ->assertSuccessful();

        $metric = ServerMetric::sole();

        $this->assertSame($server->id, $metric->server_id);
        $this->assertSame(42.5, $metric->cpu_percent);
        $this->assertSame(4, $metric->cpu_cores);
        $this->assertSame(3_221_225_472, $metric->memory_used_bytes);
        $this->assertSame(45_000_000, $metric->net_tx_bytes_per_sec);
        // The app's own count rather than anything the box claims about viewers.
        $this->assertSame(17, $metric->viewer_count);
    }

    /**
     * The box leaves rate fields out entirely on its first run, because it has no
     * previous sample to diff against. That must not cost us the rest of the row.
     */
    public function test_a_partial_sample_is_stored_with_the_missing_fields_null(): void
    {
        $server = Server::factory()->create();

        $this->heartbeat($server, ['metrics' => ['memory_used_bytes' => 100, 'memory_total_bytes' => 200]])
            ->assertSuccessful();

        $metric = ServerMetric::sole();

        $this->assertSame(100, $metric->memory_used_bytes);
        $this->assertNull($metric->cpu_percent);
        $this->assertNull($metric->net_tx_bytes_per_sec);
    }

    /**
     * A wrapped counter on the box would otherwise draw a spike that makes every other
     * sample in the window unreadable.
     */
    public function test_impossible_values_are_dropped_rather_than_charted(): void
    {
        $server = Server::factory()->create();

        $this->heartbeat($server, ['metrics' => [
            'cpu_percent' => 4000,
            'net_tx_bytes_per_sec' => -5,
            'memory_used_bytes' => 4096,
        ]])->assertSuccessful();

        $metric = ServerMetric::sole();

        $this->assertNull($metric->cpu_percent);
        $this->assertNull($metric->net_tx_bytes_per_sec);
        $this->assertSame(4096, $metric->memory_used_bytes);
    }

    public function test_a_heartbeat_without_metrics_still_marks_the_server_alive(): void
    {
        $server = Server::factory()->create(['last_heartbeat' => null]);

        $this->heartbeat($server, ['health' => ['local' => 'healthy']])->assertSuccessful();

        $this->assertSame(0, ServerMetric::count());
        $this->assertNotNull($server->fresh()->last_heartbeat);
    }

    public function test_a_wrong_shared_secret_records_nothing(): void
    {
        $server = Server::factory()->create();

        $this->heartbeat($server, ['metrics' => $this->sample()], 'wrong')->assertUnauthorized();

        $this->assertSame(0, ServerMetric::count());
    }

    // ------------------------------------------------------------- presentation

    public function test_the_service_reports_current_values_in_human_units(): void
    {
        $server = Server::factory()->create(['viewer_count' => 25, 'max_clients' => 100]);

        ServerMetric::create(['server_id' => $server->id, 'recorded_at' => now()] + $this->sample());

        $cards = collect(app(ServerMetricsService::class)->forServer($server)['cards'])
            ->keyBy('key');

        $this->assertSame('25', $cards['viewers']['value']);
        $this->assertSame('43%', $cards['cpu']['value']);
        $this->assertSame('3.0 GiB', $cards['memory']['value']);
        // Bits, because that is how an uplink is quoted.
        $this->assertSame('360 Mbit/s', $cards['net_tx']['value']);
        // Free space, not used: "how much room is left" is the question being asked.
        $this->assertSame('63.0 GiB', $cards['disk']['value']);
        $this->assertSame('of 75.0 GiB · 16% used', $cards['disk']['hint']);
        $this->assertSame('3d 2h', $cards['uptime']['value']);
    }

    public function test_a_server_that_has_never_reported_shows_dashes_not_zeroes(): void
    {
        $server = Server::factory()->create();

        $cards = collect(app(ServerMetricsService::class)->forServer($server)['cards'])->keyBy('key');

        $this->assertSame('—', $cards['cpu']['value']);
        $this->assertSame('—', $cards['memory']['value']);
        $this->assertSame([], app(ServerMetricsService::class)->forServer($server)['charts']);
    }

    /**
     * A window with a hole in it has to render as a hole: a server that stopped
     * reporting for twenty minutes must not have a line drawn across the gap.
     */
    public function test_gaps_in_the_window_stay_gaps(): void
    {
        $server = Server::factory()->create();

        foreach ([5, 6, 7] as $minutesAgo) {
            ServerMetric::create([
                'server_id' => $server->id,
                'recorded_at' => now()->subMinutes($minutesAgo),
                'cpu_percent' => 50,
            ]);
        }

        $charts = collect(app(ServerMetricsService::class)->forServer($server, '1h')['charts'])->keyBy('key');
        $points = collect($charts['cpu_percent']['points']);

        $this->assertGreaterThan(0, $points->whereNotNull('value')->count());
        $this->assertGreaterThan(0, $points->whereNull('value')->count());
    }

    public function test_disk_free_is_charted_as_headroom_not_usage(): void
    {
        $server = Server::factory()->create();

        ServerMetric::create([
            'server_id' => $server->id,
            'recorded_at' => now()->subMinute(),
            'disk_total_bytes' => 100_000_000_000,
            'disk_used_bytes' => 30_000_000_000,
        ]);

        $charts = collect(app(ServerMetricsService::class)->forServer($server, '1h')['charts'])->keyBy('key');

        $this->assertArrayHasKey('disk_free_bytes', $charts->all());
        $this->assertArrayNotHasKey('disk_used_bytes', $charts->all());
        $this->assertSame(
            70_000_000_000.0,
            collect($charts['disk_free_bytes']['points'])->whereNotNull('value')->last()['value'],
        );
    }

    public function test_an_origin_gets_no_viewer_series(): void
    {
        $server = Server::factory()->origin()->create();

        ServerMetric::create([
            'server_id' => $server->id,
            'recorded_at' => now(),
            'cpu_percent' => 10,
            'viewer_count' => 5,
        ]);

        $payload = app(ServerMetricsService::class)->forServer($server);

        $this->assertSame(ServerTypeEnum::ORIGIN, $server->type);
        $this->assertNotContains('viewers', collect($payload['cards'])->pluck('key')->all());
        $this->assertNotContains('viewer_count', collect($payload['charts'])->pluck('key')->all());
    }

    public function test_an_unknown_range_falls_back_to_the_default(): void
    {
        $server = Server::factory()->create();

        $this->assertSame(
            ServerMetricsService::DEFAULT_RANGE,
            app(ServerMetricsService::class)->forServer($server, 'all-time')['range'],
        );
    }

    // ------------------------------------------------------------------ retention

    public function test_samples_older_than_the_retention_window_are_pruned(): void
    {
        config(['stream.server.metrics_retention_days' => 7]);

        $server = Server::factory()->create();

        ServerMetric::create(['server_id' => $server->id, 'recorded_at' => now()->subDays(8), 'cpu_percent' => 1]);
        ServerMetric::create(['server_id' => $server->id, 'recorded_at' => now()->subDays(1), 'cpu_percent' => 2]);

        (new PruneServerMetricsJob)->handle();

        $this->assertSame(1, ServerMetric::count());
        $this->assertSame(2.0, ServerMetric::sole()->cpu_percent);
    }
}
