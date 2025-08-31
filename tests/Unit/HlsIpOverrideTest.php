<?php

namespace Tests\Unit;

use App\Models\User;
use App\Models\Server;
use App\Models\Source;
use App\Enum\ServerTypeEnum;
use App\Enum\ServerStatusEnum;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HlsIpOverrideTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Create a test source
        $this->source = Source::factory()->create([
            'slug' => 'test-stream',
            'name' => 'Test Stream',
        ]);
        
        // Create a test user
        $this->user = User::factory()->create([
            'streamkey' => 'test-streamkey-123',
        ]);
        
        // Create default edge servers
        $this->defaultServer = Server::create([
            'hostname' => 'default-edge.example.com',
            'port' => 8080,
            'type' => ServerTypeEnum::EDGE,
            'status' => ServerStatusEnum::ACTIVE,
            'viewer_count' => 0,
            'max_clients' => 100,
            'hetzner_id' => 'test-hetzner-id',
            'ip' => '10.0.0.1',
        ]);
    }

    public function test_variant_endpoint_uses_override_server_for_ipv4_match()
    {
        // Configure IP override
        Config::set('stream.local_streaming_ipv4', '192.168.1.100');
        Config::set('stream.local_streaming_hostname', 'local-edge.example.com');
        
        // Mock HTTP response for the variant playlist
        Http::fake([
            'http://local-edge.example.com:8080/live/test-stream_hd.m3u8' => Http::response(
                "#EXTM3U\n#EXT-X-VERSION:3\n#EXT-X-TARGETDURATION:10\n#EXTINF:10.0,\ntest-stream_hd_001.ts\n#EXTINF:10.0,\ntest-stream_hd_002.ts\n",
                200
            ),
        ]);
        
        // Simulate request from the configured IPv4
        $response = $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.100'])
            ->get('/hls/test-stream_hd.m3u8?streamkey=test-streamkey-123');
        
        $response->assertStatus(200);
        
        // Check that the response contains the override hostname
        $content = $response->getContent();
        $this->assertStringContainsString('local-edge.example.com', $content);
        $this->assertStringNotContainsString('default-edge.example.com', $content);
    }
    
    public function test_variant_endpoint_uses_override_server_for_ipv6_match()
    {
        // Configure IP override
        Config::set('stream.local_streaming_ipv6', '2001:db8::1');
        Config::set('stream.local_streaming_hostname', 'local-edge-v6.example.com');
        
        // Mock HTTP response for the variant playlist
        Http::fake([
            'http://local-edge-v6.example.com:8080/live/test-stream_hd.m3u8' => Http::response(
                "#EXTM3U\n#EXT-X-VERSION:3\n#EXT-X-TARGETDURATION:10\n#EXTINF:10.0,\ntest-stream_hd_001.ts\n#EXTINF:10.0,\ntest-stream_hd_002.ts\n",
                200
            ),
        ]);
        
        // Simulate request from the configured IPv6
        $response = $this->withServerVariables(['REMOTE_ADDR' => '2001:db8::1'])
            ->get('/hls/test-stream_hd.m3u8?streamkey=test-streamkey-123');
        
        $response->assertStatus(200);
        
        // Check that the response contains the override hostname
        $content = $response->getContent();
        $this->assertStringContainsString('local-edge-v6.example.com', $content);
        $this->assertStringNotContainsString('default-edge.example.com', $content);
    }
    
    public function test_variant_endpoint_uses_default_server_for_non_matching_ip()
    {
        // Configure IP override
        Config::set('stream.local_streaming_ipv4', '192.168.1.100');
        Config::set('stream.local_streaming_hostname', 'local-edge.example.com');
        
        // Assign the user to the default server
        $this->user->update(['server_id' => $this->defaultServer->id]);
        
        // Mock HTTP response for the variant playlist
        Http::fake([
            'http://default-edge.example.com:8080/live/test-stream_hd.m3u8' => Http::response(
                "#EXTM3U\n#EXT-X-VERSION:3\n#EXT-X-TARGETDURATION:10\n#EXTINF:10.0,\ntest-stream_hd_001.ts\n#EXTINF:10.0,\ntest-stream_hd_002.ts\n",
                200
            ),
        ]);
        
        // Simulate request from a different IP
        $response = $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.50'])
            ->get('/hls/test-stream_hd.m3u8?streamkey=test-streamkey-123');
        
        $response->assertStatus(200);
        
        // Check that the response contains the default hostname
        $content = $response->getContent();
        $this->assertStringContainsString('default-edge.example.com', $content);
        $this->assertStringNotContainsString('local-edge.example.com', $content);
    }
    
    public function test_master_endpoint_uses_override_server_for_ipv4_match()
    {
        // Configure IP override
        Config::set('stream.local_streaming_ipv4', '192.168.1.100');
        Config::set('stream.local_streaming_hostname', 'local-edge.example.com');
        
        // Mock HTTP response for the master playlist
        Http::fake([
            'http://local-edge.example.com:8080/live/test-stream_master.m3u8' => Http::response(
                "#EXTM3U\n#EXT-X-VERSION:3\n#EXT-X-STREAM-INF:BANDWIDTH=3500000,RESOLUTION=1280x720\ntest-stream_hd.m3u8\n#EXT-X-STREAM-INF:BANDWIDTH=1500000,RESOLUTION=854x480\ntest-stream_sd.m3u8\n",
                200
            ),
        ]);
        
        // Simulate request from the configured IPv4
        $response = $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.100'])
            ->get('/hls/test-stream/master.m3u8?streamkey=test-streamkey-123');
        
        $response->assertStatus(200);
        
        // The master playlist should contain variant URLs that will use our Laravel routes
        $content = $response->getContent();
        $this->assertStringContainsString('/hls/test-stream_hd.m3u8', $content);
        $this->assertStringContainsString('streamkey=test-streamkey-123', $content);
    }
    
    public function test_user_model_returns_override_server_for_matching_ip()
    {
        // Configure IP override
        Config::set('stream.local_streaming_ipv4', '192.168.1.100');
        Config::set('stream.local_streaming_hostname', 'local-edge.example.com');
        
        // Test getOrAssignServer with matching IP
        $server = $this->user->getOrAssignServer('192.168.1.100');
        
        $this->assertNotNull($server);
        $this->assertEquals('local-edge.example.com', $server->hostname);
        $this->assertEquals(8080, $server->port);
    }
    
    public function test_user_model_returns_default_server_for_non_matching_ip()
    {
        // Configure IP override
        Config::set('stream.local_streaming_ipv4', '192.168.1.100');
        Config::set('stream.local_streaming_hostname', 'local-edge.example.com');
        
        // Assign user to default server
        $this->user->update(['server_id' => $this->defaultServer->id]);
        
        // Test getOrAssignServer with non-matching IP
        $server = $this->user->getOrAssignServer('10.0.0.50');
        
        $this->assertNotNull($server);
        $this->assertEquals('default-edge.example.com', $server->hostname);
    }
    
    public function test_both_ipv4_and_ipv6_override_work_together()
    {
        // Configure both IPv4 and IPv6 overrides
        Config::set('stream.local_streaming_ipv4', '192.168.1.100');
        Config::set('stream.local_streaming_ipv6', '2001:db8::1');
        Config::set('stream.local_streaming_hostname', 'local-edge-dual.example.com');
        
        // Test IPv4
        $serverV4 = $this->user->getOrAssignServer('192.168.1.100');
        $this->assertEquals('local-edge-dual.example.com', $serverV4->hostname);
        
        // Test IPv6
        $serverV6 = $this->user->getOrAssignServer('2001:db8::1');
        $this->assertEquals('local-edge-dual.example.com', $serverV6->hostname);
        
        // Test non-matching IP
        $this->user->update(['server_id' => $this->defaultServer->id]);
        $serverDefault = $this->user->getOrAssignServer('10.0.0.50');
        $this->assertEquals('default-edge.example.com', $serverDefault->hostname);
    }
    
    public function test_override_not_applied_when_hostname_not_configured()
    {
        // Configure IPs but not hostname
        Config::set('stream.local_streaming_ipv4', '192.168.1.100');
        Config::set('stream.local_streaming_ipv6', '2001:db8::1');
        Config::set('stream.local_streaming_hostname', '');
        
        // Assign user to default server
        $this->user->update(['server_id' => $this->defaultServer->id]);
        
        // Should use default server even with matching IP
        $server = $this->user->getOrAssignServer('192.168.1.100');
        $this->assertEquals('default-edge.example.com', $server->hostname);
    }
    
    public function test_override_works_with_system_streamkey()
    {
        // Configure system streamkey
        Config::set('stream.system_streamkey', 'system-key-123');
        
        // Configure IP override
        Config::set('stream.local_streaming_ipv4', '192.168.1.100');
        Config::set('stream.local_streaming_hostname', 'local-edge.example.com');
        
        // Mock HTTP response for the variant playlist
        Http::fake([
            'http://local-edge.example.com:8080/live/test-stream_hd.m3u8' => Http::response(
                "#EXTM3U\n#EXT-X-VERSION:3\n#EXT-X-TARGETDURATION:10\n#EXTINF:10.0,\ntest-stream_hd_001.ts\n#EXTINF:10.0,\ntest-stream_hd_002.ts\n",
                200
            ),
        ]);
        
        // Simulate request from the configured IPv4 with system streamkey
        $response = $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.100'])
            ->get('/hls/test-stream_hd.m3u8?streamkey=system-key-123');
        
        $response->assertStatus(200);
        
        // Check that the response contains the override hostname
        $content = $response->getContent();
        $this->assertStringContainsString('local-edge.example.com', $content);
    }
    
    public function test_multiple_users_with_same_override_ip()
    {
        // Create another user
        $user2 = User::factory()->create([
            'streamkey' => 'test-streamkey-456',
        ]);
        
        // Configure IP override
        Config::set('stream.local_streaming_ipv4', '192.168.1.100');
        Config::set('stream.local_streaming_hostname', 'local-edge.example.com');
        
        // Both users should get the same override server
        $server1 = $this->user->getOrAssignServer('192.168.1.100');
        $server2 = $user2->getOrAssignServer('192.168.1.100');
        
        $this->assertEquals('local-edge.example.com', $server1->hostname);
        $this->assertEquals('local-edge.example.com', $server2->hostname);
    }
}