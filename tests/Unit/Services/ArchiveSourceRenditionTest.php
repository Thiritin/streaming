<?php

namespace Tests\Unit\Services;

use App\Models\Recording;
use App\Models\Source;
use App\Services\ArchivePlaylistService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The archive-only source rendition: the publisher's own bitstream, mirrored by the
 * transcoder so a cut can be pulled at contribution quality rather than at the 6 Mbps
 * fhd rung the ladder tops out at.
 *
 * It is cut on the publisher's keyframes rather than the ladder's forced 2s marks, so
 * it carries its own hour index. These tests pin that separation: the two indexes are
 * read independently, and the source rung stays out of the master unless asked for.
 */
class ArchiveSourceRenditionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Cut markers are naive timestamps read back in the app timezone, so the
        // fixtures below would otherwise have to be written in Berlin time to line up
        // with a UTC hour bucket. That is a different test than this one.
        Config::set('app.timezone', 'UTC');
        date_default_timezone_set('UTC');

        Config::set('stream.archive_disk', 'archive-test');
        Config::set('stream.archive_url_mode', 'proxy');
        Storage::fake('archive-test');

        // The proxy segment route is registered in routes/local.php, which only loads
        // when the app is local. Proxy mode is what a faked disk can serve, so stand
        // the route up here rather than reaching for presigned URLs.
        Route::get('/dev-archive/{path}', fn () => null)
            ->where('path', '.*')
            ->name('archive.segment');

        // Name lookups are built when the route files are loaded, so a route added
        // afterwards is invisible to route() until they are rebuilt.
        Route::getRoutes()->refreshNameLookups();
    }

    public function test_the_source_rendition_reads_its_own_hour_index(): void
    {
        $this->writeIndexes();

        $segments = app(ArchivePlaylistService::class)->segmentsInRange(
            'prime',
            CarbonImmutable::parse('2026-08-15T12:00:00Z'),
            CarbonImmutable::parse('2026-08-15T12:01:00Z'),
            'source',
        );

        // Two 6s source segments against the ladder's four 2s ones: the point of the
        // separate index is that the boundaries do not have to agree.
        $this->assertCount(2, $segments);
        $this->assertSame('prime_source_1700000000_000000.ts', $segments[0]['name']);
        $this->assertSame(12_000_000, $segments[0]['bytes']);
    }

    public function test_the_ladder_index_is_untouched_by_the_source_index(): void
    {
        $this->writeIndexes();

        $segments = app(ArchivePlaylistService::class)->segmentsInRange(
            'prime',
            CarbonImmutable::parse('2026-08-15T12:00:00Z'),
            CarbonImmutable::parse('2026-08-15T12:01:00Z'),
            'hd',
        );

        $this->assertCount(4, $segments);
        $this->assertSame('prime_%v_1700000000_000000.ts', $segments[0]['name']);
        $this->assertNull($segments[0]['bytes']);
    }

    public function test_the_source_rung_is_absent_from_the_master_by_default(): void
    {
        $this->writeIndexes();
        $recording = $this->recording();

        $master = app(ArchivePlaylistService::class)->renderMaster($recording);

        $this->assertStringNotContainsString('source.m3u8', $master);
        $this->assertSame(3, substr_count($master, '#EXT-X-STREAM-INF'));
    }

    public function test_the_source_rung_is_advertised_at_its_measured_bitrate_when_enabled(): void
    {
        Config::set('stream.archive_source_in_master', true);

        $this->writeIndexes();
        $recording = $this->recording();

        $master = app(ArchivePlaylistService::class)->renderMaster($recording);

        // 12 MB and 6 MB over 12s of media is 12 Mbps, measured from the byte counts
        // the uploader records rather than declared anywhere.
        $this->assertSame(4, substr_count($master, '#EXT-X-STREAM-INF'));
        $this->assertStringContainsString('#EXT-X-STREAM-INF:BANDWIDTH=12000000', $master);
        $this->assertStringContainsString('/source.m3u8', $master);
    }

    public function test_the_source_rendition_is_always_playable_explicitly(): void
    {
        $this->writeIndexes();
        $recording = $this->recording();

        $playlist = app(ArchivePlaylistService::class)->renderMedia($recording, 'source');

        $this->assertStringContainsString('#EXT-X-PLAYLIST-TYPE:VOD', $playlist);
        $this->assertStringContainsString('#EXT-X-ENDLIST', $playlist);
        // TARGETDURATION follows the real segment length, not the ladder's 2s.
        $this->assertStringContainsString('#EXT-X-TARGETDURATION:6', $playlist);
        $this->assertStringContainsString('prime_source_1700000000_000000.ts', $playlist);
    }

    protected function recording(): Recording
    {
        $source = Source::factory()->create(['slug' => 'prime']);

        return Recording::create([
            'source_id' => $source->id,
            'title' => 'Opening Ceremony',
            'slug' => 'opening-ceremony',
            'date' => '2026-08-15 12:00:00',
            'starts_at' => '2026-08-15 12:00:00',
            'ends_at' => '2026-08-15 12:01:00',
            'archive_prefix' => 'archive/prime',
            'status' => 'draft',
            'is_published' => false,
        ]);
    }

    /** Four 2s ladder segments and the two 6s source segments covering the same wall clock. */
    protected function writeIndexes(): void
    {
        $ladder = "#EXTM3U\n#EXT-X-VERSION:6\n#EXT-X-TARGETDURATION:2\n#EXT-X-INDEPENDENT-SEGMENTS\n";
        foreach (range(0, 3) as $n) {
            $ladder .= '#EXT-X-ARCHIVE-SEQ:'.$n."\n";
            $ladder .= '#EXTINF:2.000000,'."\n";
            $ladder .= '#EXT-X-PROGRAM-DATE-TIME:2026-08-15T12:00:'.sprintf('%02d', $n * 2).".000+0000\n";
            $ladder .= sprintf("prime_%%v_1700000000_%06d.ts\n", $n);
        }

        $source = "#EXTM3U\n#EXT-X-VERSION:6\n#EXT-X-TARGETDURATION:10\n#EXT-X-INDEPENDENT-SEGMENTS\n";
        foreach ([[0, 12_000_000], [1, 6_000_000]] as [$n, $bytes]) {
            $source .= '#EXT-X-ARCHIVE-SEQ:'.$n."\n";
            $source .= '#EXT-X-ARCHIVE-BYTES:'.$bytes."\n";
            $source .= '#EXTINF:6.000000,'."\n";
            $source .= '#EXT-X-PROGRAM-DATE-TIME:2026-08-15T12:00:'.sprintf('%02d', $n * 6).".000+0000\n";
            $source .= sprintf("prime_source_1700000000_%06d.ts\n", $n);
        }

        Storage::disk('archive-test')->put('archive/prime/20260815/12/index.m3u8', $ladder);
        Storage::disk('archive-test')->put('archive/prime/20260815/12/index-source.m3u8', $source);
    }
}
