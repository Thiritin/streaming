<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Server;
use App\Models\Source;
use App\Enum\ServerTypeEnum;
use App\Enum\ServerStatusEnum;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HlsCachingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Clear cache before each test
        Cache::flush();
        
        // Create a test source
        $this->source = Source::factory()->create([
            'slug' => 'test-stream',
            'name' => 'Test Stream',
        ]);
        
        // Create a test user
        $this->user = User::factory()->create([
            'streamkey' => 'test-streamkey-123',
        ]);
        
        // Create default edge server
        $this->defaultServer = Server::create([
            'hostname' => 'edge.example.com',
            'port' => 8080,
            'type' => ServerTypeEnum::EDGE,
            'status' => ServerStatusEnum::ACTIVE,
            'viewer_count' => 0,
            'max_clients' => 100,
            'hetzner_id' => 'test-hetzner-id',
            'ip' => '10.0.0.1',
        ]);
        
        // Assign user to server
        $this->user->update(['server_id' => $this->defaultServer->id]);
    }

    public function test_master_endpoint_caches_response_for_2_seconds()
    {
        // Mock HTTP responses - only expect one call due to caching
        $callCount = 0;
        Http::fake(function ($request) use (&$callCount) {
            $callCount++;
            if ($request->url() === 'http://edge.example.com:8080/live/test-stream_master.m3u8') {
                return Http::response(
                    "#EXTM3U\n#EXT-X-VERSION:3\n#EXT-X-STREAM-INF:BANDWIDTH=3500000,RESOLUTION=1280x720\ntest-stream_hd.m3u8\n",
                    200
                );
            }
        });
        
        // First request - should hit the server and cache the response
        $response1 = $this->get('/hls/test-stream/master.m3u8?streamkey=test-streamkey-123');
        $response1->assertStatus(200);
        $response1->assertHeader('X-Cache', 'MISS');
        $this->assertEquals(1, $callCount);
        
        // Second request immediately after - should use cached response
        $response2 = $this->get('/hls/test-stream/master.m3u8?streamkey=test-streamkey-123');
        $response2->assertStatus(200);
        $response2->assertHeader('X-Cache', 'HIT');
        $this->assertEquals(1, $callCount); // No additional HTTP call
        
        // Verify both responses have the same content
        $this->assertEquals($response1->getContent(), $response2->getContent());
    }

    public function test_variant_endpoint_caches_response_for_2_seconds()
    {
        // Mock HTTP responses
        $callCount = 0;
        Http::fake(function ($request) use (&$callCount) {
            $callCount++;
            if ($request->url() === 'http://edge.example.com:8080/live/test-stream_hd.m3u8') {
                return Http::response(
                    "#EXTM3U\n#EXT-X-VERSION:3\n#EXTINF:10.0,\ntest-stream_hd_001.ts\n#EXTINF:10.0,\ntest-stream_hd_002.ts\n",
                    200
                );
            }
        });
        
        // First request - should hit the server and cache the response
        $response1 = $this->get('/hls/test-stream_hd.m3u8?streamkey=test-streamkey-123');
        $response1->assertStatus(200);
        $response1->assertHeader('X-Cache', 'MISS');
        $this->assertEquals(1, $callCount);
        
        // Second request immediately after - should use cached response
        $response2 = $this->get('/hls/test-stream_hd.m3u8?streamkey=test-streamkey-123');
        $response2->assertStatus(200);
        $response2->assertHeader('X-Cache', 'HIT');
        $this->assertEquals(1, $callCount); // No additional HTTP call
        
        // Verify segment URLs are rewritten correctly in both responses
        $content = $response2->getContent();
        $this->assertStringContainsString('http://edge.example.com:8080/live/test-stream_hd_001.ts?streamkey=test-streamkey-123', $content);
    }

    public function test_cache_keys_are_unique_per_stream_server_and_auth()
    {
        // Create another user with different streamkey
        $user2 = User::factory()->create(['streamkey' => 'different-key-456']);
        $user2->update(['server_id' => $this->defaultServer->id]);
        
        // Mock HTTP responses
        $requestUrls = [];
        Http::fake(function ($request) use (&$requestUrls) {
            $requestUrls[] = $request->url();
            return Http::response(
                "#EXTM3U\n#EXT-X-VERSION:3\n#EXTINF:10.0,\ntest-stream_hd_001.ts\n",
                200
            );
        });
        
        // Request with first streamkey
        $response1 = $this->get('/hls/test-stream_hd.m3u8?streamkey=test-streamkey-123');
        $response1->assertStatus(200);
        $response1->assertHeader('X-Cache', 'MISS');
        
        // Request with second streamkey - should NOT use cache due to different key
        $response2 = $this->get('/hls/test-stream_hd.m3u8?streamkey=different-key-456');
        $response2->assertStatus(200);
        $response2->assertHeader('X-Cache', 'MISS');
        
        // Verify both requests hit the server
        $this->assertCount(2, $requestUrls);
    }

    public function test_cache_expires_after_2_seconds()
    {
        // Mock HTTP responses
        $callCount = 0;
        Http::fake(function ($request) use (&$callCount) {
            $callCount++;
            return Http::response(
                "#EXTM3U\n#EXT-X-VERSION:3\n#EXTINF:10.0,\ntest-stream_hd_00{$callCount}.ts\n",
                200
            );
        });
        
        // First request
        $response1 = $this->get('/hls/test-stream_hd.m3u8?streamkey=test-streamkey-123');
        $response1->assertStatus(200);
        $response1->assertHeader('X-Cache', 'MISS');
        $content1 = $response1->getContent();
        $this->assertStringContainsString('test-stream_hd_001.ts', $content1);
        
        // Wait for cache to expire (2+ seconds)
        $this->travel(3)->seconds();
        
        // Second request after cache expiry - should hit server again
        $response2 = $this->get('/hls/test-stream_hd.m3u8?streamkey=test-streamkey-123');
        $response2->assertStatus(200);
        $response2->assertHeader('X-Cache', 'MISS');
        $content2 = $response2->getContent();
        $this->assertStringContainsString('test-stream_hd_002.ts', $content2);
        
        // Verify server was called twice
        $this->assertEquals(2, $callCount);
    }

    public function test_failed_requests_are_not_cached()
    {
        // Mock HTTP responses - first fails, second succeeds
        $callCount = 0;
        Http::fake(function ($request) use (&$callCount) {
            $callCount++;
            if ($callCount === 1) {
                return Http::response('Server Error', 500);
            }
            return Http::response(
                "#EXTM3U\n#EXT-X-VERSION:3\n#EXTINF:10.0,\ntest-stream_hd_001.ts\n",
                200
            );
        });
        
        // First request - should fail
        $response1 = $this->get('/hls/test-stream_hd.m3u8?streamkey=test-streamkey-123');
        $response1->assertStatus(502); // Our controller returns 502 for upstream errors
        
        // Second request - should hit server again (not cached)
        $response2 = $this->get('/hls/test-stream_hd.m3u8?streamkey=test-streamkey-123');
        $response2->assertStatus(200);
        $response2->assertHeader('X-Cache', 'MISS');
        
        // Verify server was called twice
        $this->assertEquals(2, $callCount);
    }

    public function test_cache_works_with_authenticated_users()
    {
        // Mock HTTP responses
        $callCount = 0;
        Http::fake(function ($request) use (&$callCount) {
            $callCount++;
            return Http::response(
                "#EXTM3U\n#EXT-X-VERSION:3\n#EXTINF:10.0,\ntest-stream_hd_001.ts\n",
                200
            );
        });
        
        // Login as user
        $this->actingAs($this->user);
        
        // First request - authenticated without streamkey param
        $response1 = $this->get('/hls/test-stream_hd.m3u8');
        $response1->assertStatus(200);
        $response1->assertHeader('X-Cache', 'MISS');
        
        // Second request - should use cache
        $response2 = $this->get('/hls/test-stream_hd.m3u8');
        $response2->assertStatus(200);
        $response2->assertHeader('X-Cache', 'HIT');
        
        // Verify only one server call
        $this->assertEquals(1, $callCount);
    }

    public function test_cache_with_different_qualities_uses_separate_keys()
    {
        // Mock HTTP responses for different qualities
        $requestUrls = [];
        Http::fake(function ($request) use (&$requestUrls) {
            $requestUrls[] = $request->url();
            if (str_contains($request->url(), '_sd.m3u8')) {
                return Http::response("#EXTM3U\n#EXTINF:10.0,\ntest-stream_sd_001.ts\n", 200);
            } elseif (str_contains($request->url(), '_hd.m3u8')) {
                return Http::response("#EXTM3U\n#EXTINF:10.0,\ntest-stream_hd_001.ts\n", 200);
            } elseif (str_contains($request->url(), '_fhd.m3u8')) {
                return Http::response("#EXTM3U\n#EXTINF:10.0,\ntest-stream_fhd_001.ts\n", 200);
            }
        });
        
        // Request SD quality
        $responseSd = $this->get('/hls/test-stream_sd.m3u8?streamkey=test-streamkey-123');
        $responseSd->assertStatus(200);
        $responseSd->assertHeader('X-Cache', 'MISS');
        
        // Request HD quality - should not use SD cache
        $responseHd = $this->get('/hls/test-stream_hd.m3u8?streamkey=test-streamkey-123');
        $responseHd->assertStatus(200);
        $responseHd->assertHeader('X-Cache', 'MISS');
        
        // Request FHD quality - should not use other caches
        $responseFhd = $this->get('/hls/test-stream_fhd.m3u8?streamkey=test-streamkey-123');
        $responseFhd->assertStatus(200);
        $responseFhd->assertHeader('X-Cache', 'MISS');
        
        // Now request HD again - should use cache
        $responseHd2 = $this->get('/hls/test-stream_hd.m3u8?streamkey=test-streamkey-123');
        $responseHd2->assertStatus(200);
        $responseHd2->assertHeader('X-Cache', 'HIT');
        
        // Verify 3 server calls (one for each quality, not 4)
        $this->assertCount(3, $requestUrls);
    }

    public function test_cache_with_system_streamkey()
    {
        // Configure system streamkey
        config(['stream.system_streamkey' => 'system-key-999']);
        
        // Mock HTTP responses
        $callCount = 0;
        Http::fake(function ($request) use (&$callCount) {
            $callCount++;
            return Http::response(
                "#EXTM3U\n#EXT-X-VERSION:3\n#EXTINF:10.0,\ntest-stream_hd_001.ts\n",
                200
            );
        });
        
        // First request with system streamkey
        $response1 = $this->get('/hls/test-stream_hd.m3u8?streamkey=system-key-999');
        $response1->assertStatus(200);
        $response1->assertHeader('X-Cache', 'MISS');
        
        // Second request - should use cache
        $response2 = $this->get('/hls/test-stream_hd.m3u8?streamkey=system-key-999');
        $response2->assertStatus(200);
        $response2->assertHeader('X-Cache', 'HIT');
        
        // Verify only one server call
        $this->assertEquals(1, $callCount);
    }

    public function test_cache_works_with_https_edge_servers()
    {
        // Update server to use port 443 (HTTPS)
        $this->defaultServer->update(['port' => 443]);
        
        // Mock HTTPS responses
        $callCount = 0;
        Http::fake(function ($request) use (&$callCount) {
            $callCount++;
            if ($request->url() === 'https://edge.example.com/live/test-stream_hd.m3u8') {
                return Http::response(
                    "#EXTM3U\n#EXT-X-VERSION:3\n#EXTINF:10.0,\ntest-stream_hd_001.ts\n",
                    200
                );
            }
        });
        
        // First request - should use HTTPS
        $response1 = $this->get('/hls/test-stream_hd.m3u8?streamkey=test-streamkey-123');
        $response1->assertStatus(200);
        $response1->assertHeader('X-Cache', 'MISS');
        $content1 = $response1->getContent();
        $this->assertStringContainsString('https://edge.example.com/live/test-stream_hd_001.ts', $content1);
        
        // Second request - should use cache
        $response2 = $this->get('/hls/test-stream_hd.m3u8?streamkey=test-streamkey-123');
        $response2->assertStatus(200);
        $response2->assertHeader('X-Cache', 'HIT');
        
        // Verify only one server call
        $this->assertEquals(1, $callCount);
    }
}