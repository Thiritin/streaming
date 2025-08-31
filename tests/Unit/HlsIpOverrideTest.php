<?php

namespace Tests\Unit;

use App\Models\User;
use App\Models\Server;
use App\Models\Source;
use App\Enum\ServerTypeEnum;
use App\Enum\ServerStatusEnum;
use App\Helpers\IpSubnetHelper;
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

    public function test_variant_endpoint_uses_override_server_for_ipv4_subnet_match()
    {
        // Configure IPv4 subnet override
        Config::set('stream.local_streaming_ipv4_subnet', '192.168.1.0/24');
        Config::set('stream.local_streaming_hostname', 'local-edge.example.com');
        
        // Mock HTTP response for the variant playlist
        Http::fake([
            'http://local-edge.example.com:8080/live/test-stream_hd.m3u8' => Http::response(
                "#EXTM3U\n#EXT-X-VERSION:3\n#EXT-X-TARGETDURATION:10\n#EXTINF:10.0,\ntest-stream_hd_001.ts\n#EXTINF:10.0,\ntest-stream_hd_002.ts\n",
                200
            ),
        ]);
        
        // Test multiple IPs within the subnet
        $testIps = ['192.168.1.1', '192.168.1.100', '192.168.1.254'];
        
        foreach ($testIps as $testIp) {
            // Simulate request from an IP within the subnet
            $response = $this->withServerVariables(['REMOTE_ADDR' => $testIp])
                ->get('/hls/test-stream_hd.m3u8?streamkey=test-streamkey-123');
            
            $response->assertStatus(200);
            
            // Check that the response contains the override hostname
            $content = $response->getContent();
            $this->assertStringContainsString('local-edge.example.com', $content);
            $this->assertStringNotContainsString('default-edge.example.com', $content);
        }
    }
    
    public function test_variant_endpoint_uses_override_server_for_ipv6_subnet_match()
    {
        // Configure IPv6 subnet override
        Config::set('stream.local_streaming_ipv6_subnet', '2001:db8::/64');
        Config::set('stream.local_streaming_hostname', 'local-edge-v6.example.com');
        
        // Mock HTTP response for the variant playlist
        Http::fake([
            'http://local-edge-v6.example.com:8080/live/test-stream_hd.m3u8' => Http::response(
                "#EXTM3U\n#EXT-X-VERSION:3\n#EXT-X-TARGETDURATION:10\n#EXTINF:10.0,\ntest-stream_hd_001.ts\n#EXTINF:10.0,\ntest-stream_hd_002.ts\n",
                200
            ),
        ]);
        
        // Test multiple IPs within the subnet
        $testIps = ['2001:db8::1', '2001:db8::ffff', '2001:db8::1234:5678'];
        
        foreach ($testIps as $testIp) {
            // Simulate request from an IP within the subnet
            $response = $this->withServerVariables(['REMOTE_ADDR' => $testIp])
                ->get('/hls/test-stream_hd.m3u8?streamkey=test-streamkey-123');
            
            $response->assertStatus(200);
            
            // Check that the response contains the override hostname
            $content = $response->getContent();
            $this->assertStringContainsString('local-edge-v6.example.com', $content);
            $this->assertStringNotContainsString('default-edge.example.com', $content);
        }
    }
    
    public function test_variant_endpoint_uses_default_server_for_non_matching_ip()
    {
        // Configure subnet override
        Config::set('stream.local_streaming_ipv4_subnet', '192.168.1.0/24');
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
        
        // Simulate request from an IP outside the subnet
        $response = $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.50'])
            ->get('/hls/test-stream_hd.m3u8?streamkey=test-streamkey-123');
        
        $response->assertStatus(200);
        
        // Check that the response contains the default hostname
        $content = $response->getContent();
        $this->assertStringContainsString('default-edge.example.com', $content);
        $this->assertStringNotContainsString('local-edge.example.com', $content);
    }
    
    public function test_master_endpoint_uses_override_server_for_ipv4_subnet_match()
    {
        // Configure subnet override
        Config::set('stream.local_streaming_ipv4_subnet', '192.168.1.0/24');
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
    
    public function test_user_model_returns_override_server_for_matching_subnet()
    {
        // Configure subnet override
        Config::set('stream.local_streaming_ipv4_subnet', '192.168.1.0/24');
        Config::set('stream.local_streaming_hostname', 'local-edge.example.com');
        
        // Test getOrAssignServer with multiple IPs in the subnet
        $testIps = ['192.168.1.1', '192.168.1.100', '192.168.1.254'];
        
        foreach ($testIps as $testIp) {
            $server = $this->user->getOrAssignServer($testIp);
            
            $this->assertNotNull($server);
            $this->assertEquals('local-edge.example.com', $server->hostname);
            $this->assertEquals(8080, $server->port);
        }
    }
    
    public function test_user_model_returns_default_server_for_non_matching_subnet()
    {
        // Configure subnet override
        Config::set('stream.local_streaming_ipv4_subnet', '192.168.1.0/24');
        Config::set('stream.local_streaming_hostname', 'local-edge.example.com');
        
        // Assign user to default server
        $this->user->update(['server_id' => $this->defaultServer->id]);
        
        // Test getOrAssignServer with IP outside the subnet
        $server = $this->user->getOrAssignServer('10.0.0.50');
        
        $this->assertNotNull($server);
        $this->assertEquals('default-edge.example.com', $server->hostname);
    }
    
    public function test_both_ipv4_and_ipv6_subnets_work_together()
    {
        // Configure both IPv4 and IPv6 subnet overrides
        Config::set('stream.local_streaming_ipv4_subnet', '192.168.1.0/24');
        Config::set('stream.local_streaming_ipv6_subnet', '2001:db8::/64');
        Config::set('stream.local_streaming_hostname', 'local-edge-dual.example.com');
        
        // Test IPv4 subnet
        $serverV4 = $this->user->getOrAssignServer('192.168.1.100');
        $this->assertEquals('local-edge-dual.example.com', $serverV4->hostname);
        
        // Test IPv6 subnet
        $serverV6 = $this->user->getOrAssignServer('2001:db8::1234');
        $this->assertEquals('local-edge-dual.example.com', $serverV6->hostname);
        
        // Test non-matching IP
        $this->user->update(['server_id' => $this->defaultServer->id]);
        $serverDefault = $this->user->getOrAssignServer('10.0.0.50');
        $this->assertEquals('default-edge.example.com', $serverDefault->hostname);
    }
    
    public function test_override_not_applied_when_hostname_not_configured()
    {
        // Configure subnets but not hostname
        Config::set('stream.local_streaming_ipv4_subnet', '192.168.1.0/24');
        Config::set('stream.local_streaming_ipv6_subnet', '2001:db8::/64');
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
        
        // Configure subnet override
        Config::set('stream.local_streaming_ipv4_subnet', '192.168.1.0/24');
        Config::set('stream.local_streaming_hostname', 'local-edge.example.com');
        
        // Mock HTTP response for the variant playlist
        Http::fake([
            'http://local-edge.example.com:8080/live/test-stream_hd.m3u8' => Http::response(
                "#EXTM3U\n#EXT-X-VERSION:3\n#EXT-X-TARGETDURATION:10\n#EXTINF:10.0,\ntest-stream_hd_001.ts\n#EXTINF:10.0,\ntest-stream_hd_002.ts\n",
                200
            ),
        ]);
        
        // Simulate request from an IP within the subnet with system streamkey
        $response = $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.50'])
            ->get('/hls/test-stream_hd.m3u8?streamkey=system-key-123');
        
        $response->assertStatus(200);
        
        // Check that the response contains the override hostname
        $content = $response->getContent();
        $this->assertStringContainsString('local-edge.example.com', $content);
    }
    
    public function test_multiple_users_with_same_subnet_override()
    {
        // Create another user
        $user2 = User::factory()->create([
            'streamkey' => 'test-streamkey-456',
        ]);
        
        // Configure subnet override
        Config::set('stream.local_streaming_ipv4_subnet', '192.168.1.0/24');
        Config::set('stream.local_streaming_hostname', 'local-edge.example.com');
        
        // Both users should get the same override server for IPs in the subnet
        $server1 = $this->user->getOrAssignServer('192.168.1.100');
        $server2 = $user2->getOrAssignServer('192.168.1.200');
        
        $this->assertEquals('local-edge.example.com', $server1->hostname);
        $this->assertEquals('local-edge.example.com', $server2->hostname);
    }
    
    public function test_subnet_helper_validates_ipv4_subnets()
    {
        // Test valid IPv4 subnet matching
        $this->assertTrue(IpSubnetHelper::isIpInSubnet('192.168.1.100', '192.168.1.0/24'));
        $this->assertTrue(IpSubnetHelper::isIpInSubnet('192.168.1.1', '192.168.1.0/24'));
        $this->assertTrue(IpSubnetHelper::isIpInSubnet('192.168.1.254', '192.168.1.0/24'));
        
        // Test IPs outside the subnet
        $this->assertFalse(IpSubnetHelper::isIpInSubnet('192.168.2.1', '192.168.1.0/24'));
        $this->assertFalse(IpSubnetHelper::isIpInSubnet('10.0.0.1', '192.168.1.0/24'));
        
        // Test smaller subnets
        $this->assertTrue(IpSubnetHelper::isIpInSubnet('192.168.1.5', '192.168.1.0/28'));
        $this->assertFalse(IpSubnetHelper::isIpInSubnet('192.168.1.20', '192.168.1.0/28'));
    }
    
    public function test_subnet_helper_validates_ipv6_subnets()
    {
        // Test valid IPv6 subnet matching
        $this->assertTrue(IpSubnetHelper::isIpInSubnet('2001:db8::1', '2001:db8::/64'));
        $this->assertTrue(IpSubnetHelper::isIpInSubnet('2001:db8::ffff:ffff', '2001:db8::/64'));
        $this->assertTrue(IpSubnetHelper::isIpInSubnet('2001:db8:0:0:1234:5678:90ab:cdef', '2001:db8::/64'));
        
        // Test IPs outside the subnet
        $this->assertFalse(IpSubnetHelper::isIpInSubnet('2001:db9::1', '2001:db8::/64'));
        $this->assertFalse(IpSubnetHelper::isIpInSubnet('2002:db8::1', '2001:db8::/64'));
        
        // Test smaller subnets (/128 is a single host)
        $this->assertTrue(IpSubnetHelper::isIpInSubnet('2001:db8::', '2001:db8::/128'));
        $this->assertFalse(IpSubnetHelper::isIpInSubnet('2001:db8::1', '2001:db8::/128'));
    }
}