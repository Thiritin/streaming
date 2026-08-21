<?php

namespace Tests\Unit;

use App\Enum\ServerStatusEnum;
use App\Enum\ServerTypeEnum;
use App\Helpers\IpSubnetHelper;
use App\Models\Server;
use App\Models\Show;
use App\Models\Source;
use App\Models\User;
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

        // A channel is only open to viewers while a show on it is live.
        Show::factory()->live()->create(['source_id' => $this->source->id]);

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

        // Create the local override server
        Server::create([
            'hostname' => 'local-edge.example.com',
            'port' => 8080,
            'type' => ServerTypeEnum::EDGE,
            'status' => ServerStatusEnum::ACTIVE,
            'viewer_count' => 0,
            'max_clients' => 100,
            'hetzner_id' => 'test-local-hetzner-id',
            'ip' => '192.168.1.1',
        ]);

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

        // Create the local override server for IPv6
        Server::create([
            'hostname' => 'local-edge-v6.example.com',
            'port' => 8080,
            'type' => ServerTypeEnum::EDGE,
            'status' => ServerStatusEnum::ACTIVE,
            'viewer_count' => 0,
            'max_clients' => 100,
            'hetzner_id' => 'test-local-v6-hetzner-id',
            'ip' => '2001:db8::1',
        ]);

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

        // Create the local override server
        Server::create([
            'hostname' => 'local-edge.example.com',
            'port' => 8080,
            'type' => ServerTypeEnum::EDGE,
            'status' => ServerStatusEnum::ACTIVE,
            'viewer_count' => 0,
            'max_clients' => 100,
            'hetzner_id' => 'test-local-hetzner-id',
            'ip' => '192.168.1.1',
        ]);

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

    public function test_override_works_with_system_streamkey()
    {
        // Configure system streamkey
        Config::set('stream.system_streamkey', 'system-key-123');

        // Configure subnet override
        Config::set('stream.local_streaming_ipv4_subnet', '192.168.1.0/24');
        Config::set('stream.local_streaming_hostname', 'local-edge.example.com');

        // Create the local override server
        Server::create([
            'hostname' => 'local-edge.example.com',
            'port' => 8080,
            'type' => ServerTypeEnum::EDGE,
            'status' => ServerStatusEnum::ACTIVE,
            'viewer_count' => 0,
            'max_clients' => 100,
            'hetzner_id' => 'test-local-hetzner-id',
            'ip' => '192.168.1.1',
        ]);

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

    /**
     * The subnets alone are not the switch. Without a hostname there is no appliance to
     * send anyone to, so a matching IP must still be served by the normal edge.
     */
    public function test_override_not_applied_when_hostname_not_configured()
    {
        Config::set('stream.local_streaming_ipv4_subnet', '192.168.1.0/24');
        Config::set('stream.local_streaming_ipv6_subnet', '2001:db8::/64');
        Config::set('stream.local_streaming_hostname', '');

        Http::fake([
            'http://default-edge.example.com:8080/live/test-stream_hd.m3u8' => Http::response(
                "#EXTM3U\n#EXT-X-VERSION:3\n#EXTINF:10.0,\ntest-stream_hd_001.ts\n",
                200
            ),
        ]);

        $response = $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.100'])
            ->get('/hls/test-stream_hd.m3u8?streamkey=test-streamkey-123');

        $response->assertStatus(200);
        $this->assertStringContainsString('default-edge.example.com', $response->getContent());
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
