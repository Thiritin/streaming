<?php

namespace Tests\Unit\Support;

use App\Support\BroadcastEndpoint;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class BroadcastEndpointTest extends TestCase
{
    protected function reverb(array $options, array $client = []): void
    {
        Config::set('broadcasting.connections.reverb.key', 'stream');
        Config::set('broadcasting.connections.reverb.options', $options);
        Config::set('broadcasting.connections.reverb.client', $client + [
            'host' => null,
            'port' => null,
            'scheme' => null,
        ]);
    }

    public function test_a_cluster_internal_host_falls_back_to_the_app_domain(): void
    {
        Config::set('app.url', 'https://stream.example.org');
        $this->reverb(['host' => 'streaming-laravel-reverb', 'port' => 8080, 'scheme' => 'http']);

        $this->assertSame([
            'key' => 'stream',
            'host' => 'stream.example.org',
            'port' => 443,
            'scheme' => 'https',
        ], BroadcastEndpoint::forBrowser());
    }

    public function test_a_reachable_host_is_handed_over_unchanged(): void
    {
        Config::set('app.url', 'http://streaming.test');
        $this->reverb(['host' => '127.0.0.1', 'port' => 8081, 'scheme' => 'http']);

        $this->assertSame([
            'key' => 'stream',
            'host' => '127.0.0.1',
            'port' => 8081,
            'scheme' => 'http',
        ], BroadcastEndpoint::forBrowser());
    }

    public function test_an_explicit_client_host_wins(): void
    {
        Config::set('app.url', 'https://stream.example.org');
        $this->reverb(
            ['host' => 'streaming-laravel-reverb', 'port' => 8080, 'scheme' => 'http'],
            ['host' => 'ws.example.org'],
        );

        $this->assertSame([
            'key' => 'stream',
            'host' => 'ws.example.org',
            'port' => 443,
            'scheme' => 'https',
        ], BroadcastEndpoint::forBrowser());
    }
}
