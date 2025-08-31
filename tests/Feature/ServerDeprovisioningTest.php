<?php

namespace Tests\Feature;

use App\Enum\ServerStatusEnum;
use App\Enum\ServerTypeEnum;
use App\Jobs\Server\Deprovision\InitializeDeprovisioningJob;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class ServerDeprovisioningTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_are_reassigned_when_server_is_deprovisioned()
    {
        // Create two edge servers
        $serverToDeprovision = Server::create([
            'hostname' => 'edge-to-deprovision.test',
            'ip' => '10.0.0.1',
            'port' => 443,
            'type' => ServerTypeEnum::EDGE,
            'status' => ServerStatusEnum::ACTIVE,
            'viewer_count' => 50,
            'max_clients' => 100,
            'shared_secret' => 'test-secret',
        ]);

        $availableServer = Server::create([
            'hostname' => 'edge-available.test',
            'ip' => '10.0.0.2',
            'port' => 443,
            'type' => ServerTypeEnum::EDGE,
            'status' => ServerStatusEnum::ACTIVE,
            'viewer_count' => 20,
            'max_clients' => 100,
            'shared_secret' => 'test-secret',
        ]);

        // Create users assigned to the server being deprovisioned
        $users = User::factory()->count(5)->create([
            'server_id' => $serverToDeprovision->id,
            'streamkey' => 'test-key-123',
        ]);

        // Mock the Log facade to verify logging
        Log::shouldReceive('info')
            ->times(7) // 1 for initial log, 5 for each user reassignment, 1 for completion
            ->andReturnTrue();
        
        Log::shouldReceive('warning')
            ->times(0); // Should not have any warnings

        // Execute the deprovisioning job
        $job = new InitializeDeprovisioningJob($serverToDeprovision);
        $job->handle();

        // Verify all users were reassigned to the available server
        foreach ($users as $user) {
            $user->refresh();
            $this->assertEquals($availableServer->id, $user->server_id);
            $this->assertEquals('test-key-123', $user->streamkey); // Streamkey should be preserved
        }

        // Verify the server status was updated
        $serverToDeprovision->refresh();
        $this->assertEquals(ServerStatusEnum::DEPROVISIONING, $serverToDeprovision->status);
    }

    public function test_users_are_unassigned_when_no_servers_available()
    {
        // Create only one edge server (the one being deprovisioned)
        $serverToDeprovision = Server::create([
            'hostname' => 'edge-only.test',
            'ip' => '10.0.0.3',
            'port' => 443,
            'type' => ServerTypeEnum::EDGE,
            'status' => ServerStatusEnum::ACTIVE,
            'viewer_count' => 30,
            'max_clients' => 100,
            'shared_secret' => 'test-secret',
        ]);

        // Create users assigned to the server
        $users = User::factory()->count(3)->create([
            'server_id' => $serverToDeprovision->id,
            'streamkey' => 'test-key-456',
        ]);

        // Mock the Log facade
        Log::shouldReceive('info')
            ->times(2) // 1 for initial log, 1 for completion
            ->andReturnTrue();
        
        Log::shouldReceive('warning')
            ->times(3) // 3 warnings for each user that couldn't be reassigned
            ->andReturnTrue();

        // Execute the deprovisioning job
        $job = new InitializeDeprovisioningJob($serverToDeprovision);
        $job->handle();

        // Verify all users were unassigned (no servers available)
        foreach ($users as $user) {
            $user->refresh();
            $this->assertNull($user->server_id);
            $this->assertNull($user->streamkey); // Streamkey should be cleared when no server available
        }

        // Verify the server status was updated
        $serverToDeprovision->refresh();
        $this->assertEquals(ServerStatusEnum::DEPROVISIONING, $serverToDeprovision->status);
    }

    public function test_users_are_assigned_to_server_with_lowest_viewer_count()
    {
        // Create the server to be deprovisioned
        $serverToDeprovision = Server::create([
            'hostname' => 'edge-deprovision.test',
            'ip' => '10.0.0.4',
            'port' => 443,
            'type' => ServerTypeEnum::EDGE,
            'status' => ServerStatusEnum::ACTIVE,
            'viewer_count' => 40,
            'max_clients' => 100,
            'shared_secret' => 'test-secret',
        ]);

        // Create multiple available servers with different viewer counts
        $server1 = Server::create([
            'hostname' => 'edge-server1.test',
            'ip' => '10.0.0.5',
            'port' => 443,
            'type' => ServerTypeEnum::EDGE,
            'status' => ServerStatusEnum::ACTIVE,
            'viewer_count' => 60,
            'max_clients' => 100,
            'shared_secret' => 'test-secret',
        ]);

        $server2 = Server::create([
            'hostname' => 'edge-server2.test',
            'ip' => '10.0.0.6',
            'port' => 443,
            'type' => ServerTypeEnum::EDGE,
            'status' => ServerStatusEnum::ACTIVE,
            'viewer_count' => 10, // Lowest viewer count
            'max_clients' => 100,
            'shared_secret' => 'test-secret',
        ]);

        $server3 = Server::create([
            'hostname' => 'edge-server3.test',
            'ip' => '10.0.0.7',
            'port' => 443,
            'type' => ServerTypeEnum::EDGE,
            'status' => ServerStatusEnum::ACTIVE,
            'viewer_count' => 30,
            'max_clients' => 100,
            'shared_secret' => 'test-secret',
        ]);

        // Create a user assigned to the server being deprovisioned
        $user = User::factory()->create([
            'server_id' => $serverToDeprovision->id,
            'streamkey' => 'test-key-789',
        ]);

        // Mock the Log facade
        Log::shouldReceive('info')->andReturnTrue();
        Log::shouldReceive('warning')->andReturnTrue();

        // Execute the deprovisioning job
        $job = new InitializeDeprovisioningJob($serverToDeprovision);
        $job->handle();

        // Verify the user was assigned to the server with lowest viewer count
        $user->refresh();
        $this->assertEquals($server2->id, $user->server_id);
        $this->assertEquals('test-key-789', $user->streamkey); // Streamkey preserved
    }

    public function test_deprovisioning_server_with_no_users()
    {
        // Create a server with no users
        $server = Server::create([
            'hostname' => 'edge-no-users.test',
            'ip' => '10.0.0.8',
            'port' => 443,
            'type' => ServerTypeEnum::EDGE,
            'status' => ServerStatusEnum::ACTIVE,
            'viewer_count' => 0,
            'max_clients' => 100,
            'shared_secret' => 'test-secret',
        ]);

        // Mock the Log facade - should not receive any user-related logs
        Log::shouldReceive('info')->never();
        Log::shouldReceive('warning')->never();

        // Execute the deprovisioning job
        $job = new InitializeDeprovisioningJob($server);
        $job->handle();

        // Verify the server status was updated
        $server->refresh();
        $this->assertEquals(ServerStatusEnum::DEPROVISIONING, $server->status);
    }

    public function test_user_not_reassigned_to_same_server()
    {
        // Create the server to be deprovisioned (which is also the only active server initially)
        $serverToDeprovision = Server::create([
            'hostname' => 'edge-same.test',
            'ip' => '10.0.0.9',
            'port' => 443,
            'type' => ServerTypeEnum::EDGE,
            'status' => ServerStatusEnum::ACTIVE,
            'viewer_count' => 30,
            'max_clients' => 100,
            'shared_secret' => 'test-secret',
        ]);

        // Create a user
        $user = User::factory()->create([
            'server_id' => $serverToDeprovision->id,
            'streamkey' => 'test-key-999',
        ]);

        // Mock the Log facade
        Log::shouldReceive('info')->andReturnTrue();
        Log::shouldReceive('warning')->andReturnTrue();

        // Test the assignServerToUser method directly
        $result = $user->assignServerToUser();

        // Should return false as there are no other servers available
        $this->assertFalse($result);
        
        // User should be unassigned
        $user->refresh();
        $this->assertNull($user->server_id);
        $this->assertNull($user->streamkey);
    }
}