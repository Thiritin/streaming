<?php

namespace Tests\Unit\Services;

use App\Models\Recording;
use App\Models\Source;
use App\Services\ArchivePlaylistService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Rendering a media playlist is linear in segments, and an hour of archive is 1800 of
 * them: index reads off S3, a signature per segment, megabytes of body. These pin that
 * a viewer pays for that once rather than on every playlist request.
 */
class ArchivePlaylistCacheTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('app.timezone', 'UTC');
        date_default_timezone_set('UTC');

        Config::set('stream.archive_disk', 'archive-test');
        Config::set('stream.archive_url_mode', 'proxy');
        Storage::fake('archive-test');

        Route::get('/dev-archive/{path}', fn () => null)
            ->where('path', '.*')
            ->name('archive.segment');

        Route::getRoutes()->refreshNameLookups();
    }

    public function test_a_rendered_playlist_is_reused_without_touching_the_archive(): void
    {
        $this->writeIndex();
        $recording = $this->recording();

        $first = app(ArchivePlaylistService::class)->renderMedia($recording, 'hd');

        // The archive going away is the sharpest available proof that the second
        // render read nothing off the disk.
        Storage::disk('archive-test')->delete('archive/prime/20260815/12/index.m3u8');

        $this->assertSame($first, app(ArchivePlaylistService::class)->renderMedia($recording, 'hd'));
    }

    public function test_a_rebuild_renders_the_cut_again(): void
    {
        $this->writeIndex();
        $recording = $this->recording();

        app(ArchivePlaylistService::class)->renderMedia($recording, 'hd');

        $this->writeIndex(segments: 6);
        app(ArchivePlaylistService::class)->build($recording->fresh());

        $playlist = app(ArchivePlaylistService::class)->renderMedia($recording->fresh(), 'hd');

        $this->assertSame(6, substr_count($playlist, '#EXTINF'));
    }

    public function test_moving_the_markers_renders_under_a_different_key(): void
    {
        $this->writeIndex();
        $recording = $this->recording();

        app(ArchivePlaylistService::class)->renderMedia($recording, 'hd');

        $recording->update(['ends_at' => '2026-08-15 12:00:04']);

        $playlist = app(ArchivePlaylistService::class)->renderMedia($recording->fresh(), 'hd');

        $this->assertSame(2, substr_count($playlist, '#EXTINF'));
    }

    /**
     * The app-side cache is keyed by the markers, but the URLs a player holds are not:
     * every media playlist for a cut sat at the same address, so a browser obeying the
     * response's max-age replayed the range a re-trim had just replaced.
     */
    public function test_a_rebuild_readdresses_the_media_playlists(): void
    {
        $this->writeIndex();
        $recording = $this->recording();

        app(ArchivePlaylistService::class)->build($recording);

        $before = app(ArchivePlaylistService::class)->renderMaster($recording->fresh());
        $beforeUrl = $recording->fresh()->m3u8_url;

        $this->travel(2)->seconds();

        $recording->update(['ends_at' => '2026-08-15 12:00:04']);
        app(ArchivePlaylistService::class)->build($recording->fresh());

        $fresh = $recording->fresh();
        $after = app(ArchivePlaylistService::class)->renderMaster($fresh);

        $this->assertNotSame($beforeUrl, $fresh->m3u8_url);
        $this->assertNotSame($before, $after);
        $this->assertStringContainsString('v='.$fresh->playlist_built_at->getTimestamp(), $after);
        $this->assertStringContainsString('v='.$fresh->playlist_built_at->getTimestamp(), $fresh->m3u8_url);
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

    protected function writeIndex(int $segments = 4): void
    {
        $index = "#EXTM3U\n#EXT-X-VERSION:6\n#EXT-X-TARGETDURATION:2\n#EXT-X-INDEPENDENT-SEGMENTS\n";

        foreach (range(0, $segments - 1) as $n) {
            $index .= '#EXT-X-ARCHIVE-SEQ:'.$n."\n";
            $index .= '#EXTINF:2.000000,'."\n";
            $index .= '#EXT-X-PROGRAM-DATE-TIME:2026-08-15T12:00:'.sprintf('%02d', $n * 2).".000+0000\n";
            $index .= sprintf("prime_%%v_1700000000_%06d.ts\n", $n);
        }

        Storage::disk('archive-test')->put('archive/prime/20260815/12/index.m3u8', $index);
    }
}
