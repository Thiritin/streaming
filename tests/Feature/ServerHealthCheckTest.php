<?php

namespace Tests\Feature;

use App\Models\Server;
use App\Jobs\Server\ServerHealthCheckJob;
use App\Enum\ServerTypeEnum;
use App\Enum\ServerStatusEnum;
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
            'shared_secret' => 'test',
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
            'shared_secret' => 'test',
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
            'shared_secret' => 'test',
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
            'shared_secret' => 'test',
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
            'shared_secret' => 'test1',
            'max_clients' => 100,
        ]);

        $edge2 = Server::create([
            'hostname' => 'edge2.local',
            'ip' => '127.0.0.2',
            'port' => 8080,
            'type' => ServerTypeEnum::EDGE,
            'status' => ServerStatusEnum::ACTIVE,
            'shared_secret' => 'test2',
            'max_clients' => 100,
        ]);

        // Inactive edge server should be skipped
        $inactiveEdge = Server::create([
            'hostname' => 'edge3.local',
            'ip' => '127.0.0.3',
            'port' => 8080,
            'type' => ServerTypeEnum::EDGE,
            'status' => ServerStatusEnum::PROVISIONING,
            'shared_secret' => 'test3',
            'max_clients' => 100,
        ]);

        // Run the job
        $job = new ServerHealthCheckJob();
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
            'shared_secret' => 'test',
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
            'shared_secret' => 'test',
            'max_clients' => 100,
        ]);

        $server->performHealthCheck();

        // Assert that HTTPS was used
        Http::assertSent(function ($request) {
            return str_starts_with($request->url(), 'https://');
        });
    }
}
