<?php

namespace App\Console\Commands;

use App\Models\Recording;
use App\Models\Source;
use App\Services\ArchivePlaylistService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Proves a recording can actually be cut from what was just streamed.
 *
 * "Segments are being written" and "a recording plays" are different claims, and the
 * gap between them is where this pipeline hides its failures: the uploader can be
 * verifying uploads to S3 while the hour index is missing, or the index can be fine
 * while presigned URLs come back 403 because the bucket's CORS or credentials are
 * wrong. Nothing surfaces until somebody tries to watch a panel that has already
 * ended, by which point the material is gone.
 *
 * So this walks the whole way: hour indexes -> a real cut -> rendered playlists ->
 * fetching actual bytes.
 *
 *   php artisan archive:verify main --minutes=10
 */
class VerifyArchive extends Command
{
    protected $signature = 'archive:verify
        {source : Source slug the stream was published to}
        {--minutes=10 : How far back to look}
        {--keep : Leave the test recording behind instead of deleting it}';

    protected $description = 'Check that a recording can be cut and played from the segment archive';

    public function handle(ArchivePlaylistService $playlists): int
    {
        $source = Source::where('slug', $this->argument('source'))->first();

        if (! $source) {
            $this->error("No source with slug [{$this->argument('source')}].");

            return self::FAILURE;
        }

        $to = CarbonImmutable::now('UTC');
        $from = $to->subMinutes((int) $this->option('minutes'));

        $this->line("Archive for <info>{$source->slug}</info>, {$from->toTimeString()} - {$to->toTimeString()} UTC");
        $this->newLine();

        $failures = 0;

        // --- 1. the hour indexes the uploader writes -------------------------------
        $ladder = $playlists->segmentsInRange($source->slug, $from, $to, 'hd');
        $sourceRendition = $playlists->segmentsInRange($source->slug, $from, $to, 'source');

        $this->report('ladder segments indexed', count($ladder) > 0, count($ladder).' segments');
        count($ladder) > 0 || $failures++;

        // Archived but never served. Absent is a warning rather than a failure, since
        // ARCHIVE_SOURCE can legitimately be off for a source.
        $this->report(
            'source rendition indexed',
            count($sourceRendition) > 0,
            count($sourceRendition) > 0
                ? count($sourceRendition).' segments'
                : 'none - ARCHIVE_SOURCE off, or not yet uploaded',
            fatal: false,
        );

        if ($ladder === []) {
            $this->newLine();
            $this->error('Nothing indexed, so there is nothing to cut. Check archive-uploader on the origin.');

            return self::FAILURE;
        }

        // --- 2. a real cut over that window ---------------------------------------
        $recording = Recording::create([
            'source_id' => $source->id,
            'title' => 'Archive verification',
            'slug' => 'archive-verify-'.Str::lower(Str::random(8)),
            'date' => $from,
            'starts_at' => $from,
            'ends_at' => $to,
            'archive_prefix' => "archive/{$source->slug}",
            'status' => 'draft',
            'is_published' => false,
        ]);

        try {
            $built = $playlists->rebuild($recording);
            $recording->refresh();

            $this->report('cut builds', $built, $built
                ? "{$recording->segment_count} segments, {$recording->duration}s"
                : (string) $recording->build_error);
            $built || $failures++;

            if ($built) {
                // --- 3. playlists render ------------------------------------------
                $master = $playlists->renderMaster($recording);
                $this->report(
                    'master playlist',
                    str_contains($master, '#EXT-X-STREAM-INF'),
                    substr_count($master, '#EXT-X-STREAM-INF').' renditions',
                );

                $media = $playlists->renderMedia($recording, 'hd');
                $vod = str_contains($media, '#EXT-X-PLAYLIST-TYPE:VOD') && str_contains($media, '#EXT-X-ENDLIST');
                $this->report('media playlist is VOD', $vod, $vod ? 'PLAYLIST-TYPE and ENDLIST present' : 'missing VOD markers');
                $vod || $failures++;

                // --- 4. the bytes actually come back ------------------------------
                // The step that catches a bucket whose credentials or CORS are wrong,
                // which every check above would happily pass.
                $urls = collect(preg_split('/\R/', $media))
                    ->filter(fn ($l) => $l !== '' && ! str_starts_with($l, '#'))
                    ->take(3);

                $fetched = 0;
                foreach ($urls as $url) {
                    try {
                        if (Http::timeout(20)->get($url)->successful()) {
                            $fetched++;
                        }
                    } catch (\Throwable) {
                        // Counted as a miss below.
                    }
                }

                $this->report('segments fetch', $fetched === $urls->count(), "{$fetched}/{$urls->count()} returned 200");
                $fetched === $urls->count() || $failures++;
            }
        } finally {
            if (! $this->option('keep')) {
                $recording->forceDelete();
            } else {
                $this->newLine();
                $this->line("Kept as <info>{$recording->slug}</info>");
            }
        }

        // --- 5. what the archive holds overall ------------------------------------
        $range = $playlists->availableRange($source->slug);
        $this->newLine();
        $this->line(sprintf(
            '  archive spans %s to %s',
            $range['from']?->toDateTimeString() ?? 'nothing',
            $range['to']?->toDateTimeString() ?? 'nothing',
        ));

        $disk = config('stream.archive_disk');
        $this->line("  disk: {$disk} (".(Storage::disk($disk)->exists("archive/{$source->slug}") ? 'reachable' : 'PREFIX MISSING').')');

        $this->newLine();

        if ($failures > 0) {
            $this->error("{$failures} check(s) failed - recordings are not safe to rely on.");

            return self::FAILURE;
        }

        $this->info('Archive verified: a recording cuts, renders and plays.');

        return self::SUCCESS;
    }

    private function report(string $label, bool $ok, string $detail, bool $fatal = true): void
    {
        $mark = $ok ? '<info>ok  </info>' : ($fatal ? '<error>FAIL</error>' : '<comment>warn</comment>');
        $this->line(sprintf('  [%s] %-26s %s', $mark, $label, $detail));
    }
}
