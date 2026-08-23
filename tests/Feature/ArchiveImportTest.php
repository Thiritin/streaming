<?php

namespace Tests\Feature;

use App\Models\BrandingSetting;
use App\Models\Recording;
use App\Services\ArchiveImportService;
use App\Services\ArchivePlaylistService;
use App\Support\ArchiveLadder;
use App\Support\ImportKey;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ArchiveImportTest extends TestCase
{
    use RefreshDatabase;

    protected ArchiveImportService $imports;

    protected function setUp(): void
    {
        parent::setUp();

        config(['stream.archive_disk' => 'dvr']);
        Storage::fake('dvr');

        $this->imports = new ArchiveImportService(new ArchivePlaylistService('dvr'), 'dvr');
    }

    /** Segments a client would have uploaded, written straight to the fake bucket. */
    protected function uploadSegments(array $import, int $count, array $renditions): array
    {
        $segments = [];
        $pdt = CarbonImmutable::parse($import['starts_at'])->utc();

        for ($n = 0; $n < $count; $n++) {
            $hour = $pdt->format('Ymd/H');

            foreach ($renditions as $rendition) {
                Storage::disk('dvr')->put(
                    sprintf(
                        'archive/%s/%s/%s_%s_%s_%06d.ts',
                        $import['prefix'], $hour, $import['prefix'], $rendition, $import['session'], $n
                    ),
                    'segment',
                );
            }

            $segments[] = ['number' => $n, 'duration' => 2.0];
            $pdt = $pdt->addSeconds(2);
        }

        return $segments;
    }

    public function test_an_import_becomes_a_cut_over_the_archive(): void
    {
        $started = $this->imports->start(['title' => 'Opening Ceremony', 'prefix' => 'vod']);
        $import = $started['import'];

        $renditions = array_keys(ArchiveLadder::renditions());
        $segments = $this->uploadSegments($import, 3, $renditions);

        $recording = $this->imports->commit($import, $segments, $renditions);

        $this->assertSame('ready', $recording->status);
        $this->assertSame(3, $recording->segment_count);
        $this->assertSame(6, $recording->duration);
        $this->assertSame('archive/vod', $recording->archive_prefix);
        $this->assertFalse((bool) $recording->is_published);

        // The index the server wrote is what a cut is later assembled from, so it has to
        // carry the generic %v name rather than any one rendition.
        $hour = CarbonImmutable::parse($import['starts_at'])->utc()->format('Ymd/H');
        $index = Storage::disk('dvr')->get("archive/vod/{$hour}/index.m3u8");

        $this->assertStringContainsString('#EXT-X-PROGRAM-DATE-TIME:', $index);
        $this->assertStringContainsString("vod_%v_{$import['session']}_000000.ts", $index);
        $this->assertStringContainsString('#EXT-X-ARCHIVE-SEQ:2', $index);
    }

    public function test_commit_refuses_when_a_segment_never_landed(): void
    {
        $started = $this->imports->start(['title' => 'Half An Upload', 'prefix' => 'vod']);
        $import = $started['import'];

        $renditions = array_keys(ArchiveLadder::renditions());
        $segments = $this->uploadSegments($import, 2, $renditions);

        // A third segment the index would promise, which nothing ever uploaded.
        $segments[] = ['number' => 2, 'duration' => 2.0];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/not in the bucket/');

        $this->imports->commit($import, $segments, $renditions);

        $this->assertSame(0, Recording::count());
    }

    public function test_a_second_import_is_allocated_a_later_window(): void
    {
        $first = $this->imports->start(['title' => 'First', 'prefix' => 'vod'])['import'];
        $renditions = array_keys(ArchiveLadder::renditions());
        $this->imports->commit($first, $this->uploadSegments($first, 2, $renditions), $renditions);

        $second = $this->imports->start(['title' => 'Second', 'prefix' => 'vod'])['import'];

        $this->assertTrue(
            CarbonImmutable::parse($second['starts_at'])
                ->greaterThan(CarbonImmutable::parse($first['starts_at'])->addHour()),
            'A second import must not share an hour bucket with the first.'
        );
    }

    public function test_imports_opened_at_the_same_time_do_not_share_a_window(): void
    {
        // What running four copies of the CLI at once looks like: every import opens before
        // any of them has uploaded a segment, so nothing about the bucket tells them apart.
        $imports = collect(range(1, 4))
            ->map(fn (int $n) => $this->imports->start(['title' => "Import {$n}", 'prefix' => 'vod'])['import']);

        $starts = $imports->map(fn (array $import) => CarbonImmutable::parse($import['starts_at'])->utc());

        $this->assertCount(4, $starts->unique(fn (CarbonImmutable $at) => $at->toIso8601String()));

        // Disjoint by more than an import can ever write, so no two of them can reach the
        // same hour index however long each one turns out to be.
        $ordered = $starts->sort()->values();

        for ($i = 1; $i < $ordered->count(); $i++) {
            $this->assertTrue(
                $ordered[$i]->greaterThan($ordered[$i - 1]->addHours(24)),
                'Concurrent imports must be given windows that cannot overlap.'
            );
        }
    }

    public function test_concurrent_imports_each_commit_their_own_recording(): void
    {
        $renditions = array_keys(ArchiveLadder::renditions());

        $first = $this->imports->start(['title' => 'First', 'prefix' => 'vod'])['import'];
        $second = $this->imports->start(['title' => 'Second', 'prefix' => 'vod'])['import'];

        $firstSegments = $this->uploadSegments($first, 3, $renditions);
        $secondSegments = $this->uploadSegments($second, 5, $renditions);

        // Committed out of order, which is what two encodes of different lengths do.
        $secondRecording = $this->imports->commit($second, $secondSegments, $renditions);
        $firstRecording = $this->imports->commit($first, $firstSegments, $renditions);

        $this->assertSame(3, $firstRecording->segment_count);
        $this->assertSame(5, $secondRecording->segment_count);
        $this->assertNotEquals($firstRecording->starts_at, $secondRecording->starts_at);
    }

    public function test_the_api_refuses_an_unknown_import(): void
    {
        BrandingSetting::setValue(ImportKey::KEY, 'an-import-key-long-enough');

        $this->postJson('/api/recording/imports/does-not-exist/commit', [
            'renditions' => ['hd'],
            'segments' => [['number' => 0, 'duration' => 2.0]],
        ], ['X-Import-Key' => 'an-import-key-long-enough'])->assertNotFound();
    }

    public function test_the_api_rejects_a_request_without_the_key(): void
    {
        BrandingSetting::setValue(ImportKey::KEY, 'an-import-key-long-enough');

        $this->postJson('/api/recording/imports', ['title' => 'Nope'])->assertUnauthorized();
        $this->postJson('/api/recording/imports', ['title' => 'Nope'], ['X-Import-Key' => 'wrong'])
            ->assertUnauthorized();
    }

    /**
     * An installation that never set a key does not have importing switched on, so an
     * empty setting has to refuse rather than wave everything through.
     */
    public function test_an_unset_import_key_refuses_every_request(): void
    {
        BrandingSetting::setValue(ImportKey::KEY, '');

        $this->postJson('/api/recording/imports', ['title' => 'Nope'], ['X-Import-Key' => ''])
            ->assertUnauthorized();
    }

    public function test_the_recording_api_key_does_not_open_the_import_api(): void
    {
        // The two keys are separate on purpose: a deploy-time secret is not the thing you
        // hand to whoever is cutting recordings this weekend.
        config(['app.recording_api_key' => 'the-old-deploy-key']);
        BrandingSetting::setValue(ImportKey::KEY, 'an-import-key-long-enough');

        $this->postJson('/api/recording/imports', ['title' => 'Nope'], [
            'X-Recording-Api-Key' => 'the-old-deploy-key',
        ])->assertUnauthorized();
    }

    public function test_an_import_opens_with_the_settings_key(): void
    {
        BrandingSetting::setValue(ImportKey::KEY, 'an-import-key-long-enough');

        $response = $this->postJson('/api/recording/imports', [
            'title' => 'Opening Ceremony',
        ], ['X-Import-Key' => 'an-import-key-long-enough'])->assertCreated();

        $this->assertSame(ArchiveImportService::DEFAULT_PREFIX, $response->json('data.import.prefix'));
        $this->assertNotEmpty($response->json('data.recipe.renditions.hd.video_bitrate'));
    }
}
