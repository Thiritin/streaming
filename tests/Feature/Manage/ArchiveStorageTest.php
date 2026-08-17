<?php

namespace Tests\Feature\Manage;

use App\Http\Middleware\HandleInertiaRequests;
use App\Jobs\ScanArchiveStorageJob;
use App\Models\Recording;
use App\Models\Source;
use App\Services\ArchivePlaylistService;
use App\Services\ArchiveStorageService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesManageUsers;
use Tests\TestCase;

/**
 * What the recordings page can say about disk, and how much of it is measured rather
 * than guessed.
 *
 * Two numbers, with different standing. A recording's size is derived from the hour
 * indexes and is exact only for the source rendition; the bucket total is a real listing
 * and exact, but it costs minutes, so it is only ever read out of the cache.
 */
class ArchiveStorageTest extends TestCase
{
    use CreatesManageUsers;
    use RefreshDatabase;

    private const START = '2026-08-17 02:00:00';

    private Source $source;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('stream.archive_disk', 'dvr'));
        Cache::forget(ArchiveStorageService::CACHE_KEY);

        $this->createManageUsers();
        $this->source = Source::factory()->create(['slug' => 'main']);
    }

    /**
     * One hour of archive: an hour of ladder, and a source rendition whose segments
     * carry real sizes.
     */
    private function writeArchive(): void
    {
        $disk = Storage::disk(config('stream.archive_disk', 'dvr'));
        $start = CarbonImmutable::parse(self::START, 'UTC');

        $ladder = ['#EXTM3U', '#EXT-X-VERSION:6'];
        $source = ['#EXTM3U', '#EXT-X-VERSION:6'];

        // 30 segments of 2s is a minute of ladder, which is enough to check the
        // arithmetic without writing an hour of playlist into a test.
        for ($i = 0; $i < 30; $i++) {
            $pdt = $start->addSeconds($i * 2);

            $ladder[] = '#EXT-X-ARCHIVE-SEQ:'.$i;
            $ladder[] = '#EXT-X-PROGRAM-DATE-TIME:'.$pdt->toIso8601ZuluString();
            $ladder[] = '#EXTINF:2.000,';
            $ladder[] = "main_hd_{$i}.ts";

            $source[] = '#EXT-X-ARCHIVE-SEQ:'.$i;
            $source[] = '#EXT-X-ARCHIVE-BYTES:1000000';
            $source[] = '#EXT-X-PROGRAM-DATE-TIME:'.$pdt->toIso8601ZuluString();
            $source[] = '#EXTINF:2.000,';
            $source[] = "main_source_{$i}.ts";
        }

        $hour = $start->format('Ymd/H');
        $disk->put("archive/main/{$hour}/index.m3u8", implode("\n", $ladder)."\n");
        $disk->put("archive/main/{$hour}/index-source.m3u8", implode("\n", $source)."\n");
    }

    private function recording(): Recording
    {
        return Recording::create([
            'source_id' => $this->source->id,
            'title' => 'Opening Ceremony',
            'slug' => 'opening-ceremony',
            'date' => self::START,
            'starts_at' => self::START,
            'ends_at' => CarbonImmutable::parse(self::START, 'UTC')->addMinutes(1),
            'archive_prefix' => 'archive/main',
            'is_published' => false,
        ]);
    }

    #[Test]
    public function building_a_cut_records_how_much_archive_it_spans(): void
    {
        $this->writeArchive();

        $recording = $this->recording();
        app(ArchivePlaylistService::class)->build($recording);

        // 60s of ladder at 1.5 + 3.5 + 6 Mbps is 11 Mbps, so 82_500_000 bytes, plus the
        // 30 source segments of exactly 1 MB each that the index declares.
        $this->assertSame(82_500_000 + 30_000_000, $recording->fresh()->archive_bytes);
    }

    #[Test]
    public function the_size_is_null_until_a_cut_is_built(): void
    {
        $this->assertNull($this->recording()->archive_bytes);
    }

    #[Test]
    public function the_listing_shows_a_size_per_recording(): void
    {
        $this->writeArchive();

        $recording = $this->recording();
        app(ArchivePlaylistService::class)->build($recording);

        $this->actingAs($this->admin)
            ->get(route('manage.recordings.index'))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Manage/Recordings/Index')
                ->where('table.rows.0.cells.size.display', '~107.3 MB')
                ->etc());
    }

    /**
     * The listing must not pay for a bucket walk. It is minutes of paginated requests,
     * and a page that ran it inline would time out the first time anyone opened it.
     */
    #[Test]
    public function the_storage_panel_is_deferred_and_never_scans_on_a_page_load(): void
    {
        $this->actingAs($this->admin)
            ->get(route('manage.recordings.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->missing('storage')
                ->etc());

        // Nothing wrote a scan result, so the page cannot have taken one on the way past.
        $this->assertFalse(app(ArchiveStorageService::class)->usage()['configured']);
    }

    #[Test]
    public function usage_reports_itself_unmeasured_until_a_scan_has_run(): void
    {
        $usage = app(ArchiveStorageService::class)->usage();

        $this->assertFalse($usage['configured']);
        $this->assertNull($usage['bytes']);
        $this->assertSame([], $usage['prefixes']);
    }

    #[Test]
    public function free_space_is_only_reported_when_a_quota_is_configured(): void
    {
        Cache::forever(ArchiveStorageService::CACHE_KEY, [
            'configured' => true,
            'scanned_at' => now()->toIso8601String(),
            'bytes' => 1_000,
            'objects' => 2,
            'quota' => null,
            'free' => null,
            'percent' => null,
            'partial' => false,
            'error' => null,
            'prefixes' => [],
        ]);

        $this->actingAs($this->admin)
            ->withHeaders([
                'X-Inertia' => 'true',
                'X-Inertia-Version' => (new HandleInertiaRequests)->version(request()),
                'X-Inertia-Partial-Data' => 'storage',
                'X-Inertia-Partial-Component' => 'Manage/Recordings/Index',
            ])
            ->get(route('manage.recordings.index'))
            ->assertOk()
            // Asserted on the raw partial payload rather than through AssertableInertia:
            // a deferred-only partial carries just the resolved props, not the full page
            // object that helper insists on.
            ->assertJsonPath('props.storage.used', '1.0 KB')
            ->assertJsonPath('props.storage.free', null)
            ->assertJsonPath('props.storage.quota', null);
    }

    #[Test]
    public function rescanning_queues_the_listing_rather_than_running_it_inline(): void
    {
        Queue::fake();

        $this->actingAs($this->admin)
            ->post(route('manage.recordings.storage.rescan'))
            ->assertRedirect();

        Queue::assertPushed(ScanArchiveStorageJob::class);
    }

    #[Test]
    public function a_moderator_cannot_trigger_a_rescan(): void
    {
        Queue::fake();

        $this->actingAs($this->moderator)
            ->post(route('manage.recordings.storage.rescan'))
            ->assertForbidden();

        Queue::assertNothingPushed();
    }
}
