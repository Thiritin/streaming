<?php

namespace Tests\Feature;

use App\Enum\ServerStatusEnum;
use App\Enum\ServerTypeEnum;
use App\Jobs\Server\ServerHealthCheckJob;
use App\Models\Server;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class ServerHealthCheckTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that health checks are only performed on edge servers
     */
    public function test_health_check_only_runs_for_edge_servers(): void
    {
        // Create origin server
        $originServer = Server::create([
            'hostname' => 'origin-test',
            'ip' => '127.0.0.1',
            'port' => 8080,
            'type' => ServerTypeEnum::ORIGIN,
            'status' => ServerStatusEnum::ACTIVE,
            'max_clients' => 0,
        ]);

        // Origin servers should always return true without making HTTP request
        $result = $originServer->performHealthCheck();

        $this->assertTrue($result);
        // Origin servers don't get health status updated
        $originServer->refresh();
        $this->assertNotEquals('healthy', $originServer->health_status);
    }

    /**
     * Test successful health check updates status to healthy
     */
    public function test_successful_health_check_marks_server_healthy(): void
    {
        Http::fake([
            'http://edge-test.local:8080/health' => Http::response(['status' => 'ok'], 200),
        ]);

        $server = Server::create([
            'hostname' => 'edge-test.local',
            'ip' => '127.0.0.1',
            'port' => 8080,
            'type' => ServerTypeEnum::EDGE,
            'status' => ServerStatusEnum::ACTIVE,
            'max_clients' => 100,
            'health_status' => 'unknown',
        ]);

        $result = $server->performHealthCheck();

        $this->assertTrue($result);
        $server->refresh();
        $this->assertEquals('healthy', $server->health_status);
        $this->assertEquals('Health check passed', $server->health_check_message);
        $this->assertNotNull($server->last_health_check);
    }

    /**
     * Test failed health check updates status to unhealthy
     */
    public function test_failed_health_check_marks_server_unhealthy(): void
    {
        Http::fake([
            'http://edge-test.local:8080/health' => Http::response('Service Unavailable', 503),
        ]);

        Log::shouldReceive('error')
            ->once()
            ->with('Server health check failed', \Mockery::type('array'));

        $server = Server::create([
            'hostname' => 'edge-test.local',
            'ip' => '127.0.0.1',
            'port' => 8080,
            'type' => ServerTypeEnum::EDGE,
            'status' => ServerStatusEnum::ACTIVE,
            'max_clients' => 100,
            'health_status' => 'healthy',
        ]);

        $result = $server->performHealthCheck();

        $this->assertFalse($result);
        $server->refresh();
        $this->assertEquals('unhealthy', $server->health_status);
        $this->assertStringContainsString('HTTP 503', $server->health_check_message);
        $this->assertNotNull($server->last_health_check);
    }

    /**
     * Test health check timeout marks server as unhealthy
     */
    public function test_health_check_timeout_marks_server_unhealthy(): void
    {
        Http::fake(function ($request) {
            throw new \Exception('Connection timeout');
        });

        Log::shouldReceive('error')
            ->once()
            ->with('Server health check exception', \Mockery::type('array'));

        $server = Server::create([
            'hostname' => 'edge-test.local',
            'ip' => '127.0.0.1',
            'port' => 8080,
            'type' => ServerTypeEnum::EDGE,
            'status' => ServerStatusEnum::ACTIVE,
            'max_clients' => 100,
        ]);

        $result = $server->performHealthCheck();

        $this->assertFalse($result);
        $server->refresh();
        $this->assertEquals('unhealthy', $server->health_status);
        $this->assertStringContainsString('Connection timeout', $server->health_check_message);
    }

    /**
     * Test the health check job processes all active edge servers
     */
    public function test_health_check_job_processes_all_active_edge_servers(): void
    {
        Http::fake([
            '*' => Http::response(['status' => 'ok'], 200),
        ]);

        // Create multiple edge servers
        $edge1 = Server::create([
            'hostname' => 'edge1.local',
            'ip' => '127.0.0.1',
            'port' => 8080,
            'type' => ServerTypeEnum::EDGE,
            'status' => ServerStatusEnum::ACTIVE,
            'max_clients' => 100,
        ]);

        $edge2 = Server::create([
            'hostname' => 'edge2.local',
            'ip' => '127.0.0.2',
            'port' => 8080,
            'type' => ServerTypeEnum::EDGE,
            'status' => ServerStatusEnum::ACTIVE,
            'max_clients' => 100,
        ]);

        // Inactive edge server should be skipped
        $inactiveEdge = Server::create([
            'hostname' => 'edge3.local',
            'ip' => '127.0.0.3',
            'port' => 8080,
            'type' => ServerTypeEnum::EDGE,
            'status' => ServerStatusEnum::PROVISIONING,
            'max_clients' => 100,
        ]);

        // Run the job
        $job = new ServerHealthCheckJob;
        $job->handle();

        // Check that active servers were checked
        $edge1->refresh();
        $edge2->refresh();
        $inactiveEdge->refresh();

        $this->assertEquals('healthy', $edge1->health_status);
        $this->assertEquals('healthy', $edge2->health_status);
        $this->assertNotEquals('healthy', $inactiveEdge->health_status); // Should not be checked
    }

    /**
     * Test hasRecentHealthCheck method
     */
    public function test_has_recent_health_check_method(): void
    {
        $server = Server::create([
            'hostname' => 'edge-test.local',
            'ip' => '127.0.0.1',
            'port' => 8080,
            'type' => ServerTypeEnum::EDGE,
            'status' => ServerStatusEnum::ACTIVE,
            'max_clients' => 100,
        ]);

        // No health check yet
        $this->assertFalse($server->hasRecentHealthCheck());

        // Recent health check
        $server->update(['last_health_check' => now()]);
        $this->assertTrue($server->hasRecentHealthCheck());

        // Old health check (3 minutes ago)
        $server->update(['last_health_check' => now()->subMinutes(3)]);
        $this->assertFalse($server->hasRecentHealthCheck());
    }

    /**
     * Test that HTTPS is used for port 443
     */
    public function test_https_used_for_port_443(): void
    {
        Http::fake([
            'https://edge-test.local/health' => Http::response(['status' => 'ok'], 200),
        ]);

        $server = Server::create([
            'hostname' => 'edge-test.local',
            'ip' => '127.0.0.1',
            'port' => 443,
            'type' => ServerTypeEnum::EDGE,
            'status' => ServerStatusEnum::ACTIVE,
            'max_clients' => 100,
        ]);

        $server->performHealthCheck();

        // Assert that HTTPS was used
        Http::assertSent(function ($request) {
            return str_starts_with($request->url(), 'https://');
        });
    }

    // ---------------------------------------------------------------- readiness

    /**
     * Provisioning readiness, which is a different check from the recurring health
     * check above and used to be broken in three separate ways at once.
     *
     * It asked for `/ready`, which nothing serves; it required `json('code') === 0`,
     * where `/health` answers plain text; and for origins it targeted `{ip}:1985`, the
     * SRS admin API, which the `Origin Server` firewall does not permit and should not.
     * The only caller retries thirty times and then fails, so all of that presented as
     * a server stuck in `provisioning` with no explanation.
     */
    public function test_readiness_probes_health_over_443_for_an_origin(): void
    {
        Http::fake(['*' => Http::response('healthy', 200)]);

        $origin = Server::factory()->origin()->create([
            'hostname' => 'origin-1.example.test',
            'ip' => '203.0.113.10',
            'provider' => 'hetzner',
            'external_id' => '12345',
        ]);

        $this->assertTrue($origin->isReady());

        Http::assertSent(fn ($request) => $request->url() === 'https://origin-1.example.test/health');

        // The port that used to be probed is the SRS admin API. It must not be touched:
        // it is not in the firewall, and opening it would expose stream control.
        Http::assertNotSent(fn ($request) => str_contains($request->url(), ':1985'));
    }

    public function test_readiness_probes_the_same_endpoint_for_an_edge(): void
    {
        Http::fake(['*' => Http::response('healthy', 200)]);

        $edge = Server::factory()->create([
            'hostname' => 'edge-1.example.test',
            'provider' => 'hetzner',
            'external_id' => '12346',
        ]);

        $this->assertTrue($edge->isReady());

        Http::assertSent(fn ($request) => $request->url() === 'https://edge-1.example.test/health');
    }

    public function test_a_server_that_is_not_serving_yet_is_not_ready(): void
    {
        Http::fake(['*' => Http::response('', 502)]);

        $origin = Server::factory()->origin()->cloud()->create();

        $this->assertFalse($origin->isReady());
    }

    /**
     * A 200 from something that is not the health endpoint - a captive portal, a parked
     * page, a misrouted proxy - must not count as ready.
     */
    public function test_a_200_from_something_else_is_not_ready(): void
    {
        Http::fake(['*' => Http::response('<html>hello</html>', 200)]);

        $origin = Server::factory()->origin()->cloud()->create();

        $this->assertFalse($origin->isReady());
    }
}
