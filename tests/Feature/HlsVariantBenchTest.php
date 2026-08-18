<?php

namespace Tests\Feature;

use App\Enum\ServerStatusEnum;
use App\Enum\ServerTypeEnum;
use App\Models\Server;
use App\Models\Source;
use App\Models\SourceUser;
use App\Models\User;
use App\Services\PlaybackTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HlsVariantBenchTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Timing, not correctness, so it is skipped unless asked for:
     *
     *   BENCH=1 php artisan test tests/Feature/HlsVariantBenchTest.php
     *
     * Numbers are an upper bound on throughput, not a prediction: sqlite in memory
     * is faster than Postgres, and there is no real network to the edge.
     */
    public function test_bench_variant_playlist_for_300_viewers(): void
    {
        if (! env('BENCH')) {
            $this->markTestSkipped('Set BENCH=1 to run the playlist benchmark.');
        }

        config(['stream.token.viewer_secret' => str_repeat('a', 64)]);
        Cache::flush();

        $source = Source::factory()->create(['slug' => 'test-stream', 'name' => 'Test Stream']);

        $server = Server::create([
            'hostname' => 'edge.example.com',
            'port' => 8080,
            'type' => ServerTypeEnum::EDGE,
            'status' => ServerStatusEnum::ACTIVE,
            'viewer_count' => 0,
            'max_clients' => 1000,
            'hetzner_id' => 'bench',
            'ip' => '10.0.0.1',
        ]);

        // A realistic body: 1800 segments is the full 60 minute DVR window.
        $body = "#EXTM3U\n#EXT-X-VERSION:3\n#EXT-X-TARGETDURATION:2\n";
        for ($i = 0; $i < 1800; $i++) {
            $body .= sprintf("#EXTINF:2.0,\ntest-stream_hd_1700000000_%06d.ts\n", $i);
        }

        Http::fake(fn () => Http::response($body, 200));

        // The edge comes from the viewer's session row, which the first request creates.
        $users = User::factory()->count(300)->create()
            ->each(fn (User $u) => $u->forceFill(['streamkey' => 'key-'.$u->id])->save());

        // Warm the route/container so the first viewer does not pay for booting.
        $this->actingAs($users->first())->get('/hls/test-stream_hd.m3u8');

        $timings = [];
        foreach ($users as $user) {
            $start = hrtime(true);
            $response = $this->actingAs($user)->get('/hls/test-stream_hd.m3u8');
            $timings[] = (hrtime(true) - $start) / 1e6;

            $response->assertStatus(200);
        }

        sort($timings);
        $total = array_sum($timings);
        $p = fn (float $q) => $timings[(int) floor($q * (count($timings) - 1))];

        // Token minting on its own, to separate it from the rest of the request.
        $tokens = app(PlaybackTokenService::class);
        $mintStart = hrtime(true);
        for ($i = 0; $i < 300; $i++) {
            $tokens->issueViewer(user: $users[$i], source: $source, edge: $server->hostname);
        }
        $mintTotal = (hrtime(true) - $mintStart) / 1e6;

        // Cost of the substitution itself, over a body with 1800 placeholders.
        $placeheld = str_replace(
            "\n",
            "?__PLAYBACK_CREDENTIAL__\n",
            str_repeat("test-stream_hd_1700000000_000000.ts\n", 1800)
        );
        $subStart = hrtime(true);
        for ($i = 0; $i < 300; $i++) {
            str_replace('?__PLAYBACK_CREDENTIAL__', '?t=v1.'.str_repeat('x', 200), $placeheld);
        }
        $subTotal = (hrtime(true) - $subStart) / 1e6;

        // Session bookkeeping, which is the one DB touch on this path.
        $dbStart = hrtime(true);
        foreach ($users as $user) {
            SourceUser::where('source_id', $source->id)
                ->where('user_id', $user->id)
                ->whereNull('left_at')
                ->first();
        }
        $dbTotal = (hrtime(true) - $dbStart) / 1e6;

        fwrite(STDERR, sprintf(
            "\n--- 300 viewers, /hls/test-stream_hd.m3u8 (1800-segment playlist) ---\n".
            "total        %8.1f ms\n".
            "mean         %8.2f ms\n".
            "p50          %8.2f ms\n".
            "p95          %8.2f ms\n".
            "p99          %8.2f ms\n".
            "max          %8.2f ms\n".
            "throughput   %8.0f req/s (single process, serial)\n".
            "upstream fetches %4d (rest served from the shared cache entry)\n".
            "token mint   %8.4f ms each (%.1f ms for 300)\n".
            "substitution %8.4f ms each (1800 placeholders)\n".
            "session read %8.4f ms each (sqlite :memory:, not Postgres)\n".
            "body size    %8.1f KB\n\n",
            $total,
            $total / count($timings),
            $p(0.50), $p(0.95), $p(0.99),
            end($timings),
            count($timings) / ($total / 1000),
            count(Http::recorded()),
            $mintTotal / 300, $mintTotal,
            $subTotal / 300,
            $dbTotal / 300,
            strlen($body) / 1024,
        ));

        $this->assertTrue(true);
    }
}
