<?php

namespace App\Services;

use App\Models\Recording;
use App\Support\ArchiveLadder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Imports an offline edit into the segment archive, so it can be cut like anything else.
 *
 * The site has no notion of "a video file": a recording is a range of a source's archive
 * selected by wall clock (see ArchivePlaylistService). An edit that never went out live
 * therefore has to become archive first, which means the ladder, the hour indexes and the
 * segment naming the live pipeline produces.
 *
 * The encode happens on the importer's machine - the master is already there, and so is
 * idle CPU - so this service only does the three things a client must not be trusted with:
 *
 *   start    pick the archive window the import occupies, and hand back the ladder to
 *            encode against, so a client shipped months ago still produces today's rungs
 *   presign  mint per-object upload URLs, each checked against that window, so an API key
 *            cannot be used to write anywhere else in the bucket
 *   commit   write the hour indexes from the durations the client reports, verify every
 *            segment actually landed, and create the recording
 *
 * The index format never leaves the server. A client reports "segment 41 was 2.002s" and
 * nothing more, so the archive's own file format cannot drift out of a released binary.
 */
class ArchiveImportService
{
    protected const CACHE_PREFIX = 'archive-import:';

    protected const TTL_HOURS = 48;

    /**
     * How long a single import may run in archive time. Generous - a con's longest single
     * session is a few hours - and it exists to bound what one API key can write, not to
     * express a policy about recording length.
     */
    protected const MAX_IMPORT_HOURS = 24;

    /** Default prefix for imports, kept away from the sources that actually stream. */
    public const DEFAULT_PREFIX = 'vod';

    protected const ARCHIVE_PREFIX = 'archive';

    protected string $disk;

    public function __construct(protected ArchivePlaylistService $playlists, ?string $disk = null)
    {
        $this->disk = $disk ?? config('stream.archive_disk', 'dvr');
    }

    /**
     * Open an import and reserve the archive window it will occupy.
     *
     * The window is allocated server side rather than asked for, because two people
     * importing on the same afternoon would otherwise interleave their segments into one
     * hour index and each other's cuts. Allocation is sequential: start after whatever the
     * prefix already holds, on the next hour boundary.
     *
     * @return array<string, mixed>
     */
    public function start(array $meta): array
    {
        $prefix = $meta['prefix'] ?? self::DEFAULT_PREFIX;

        if (! preg_match('/^[a-z0-9-]+$/', $prefix)) {
            throw new \InvalidArgumentException('Archive prefix may only contain lowercase letters, numbers and dashes.');
        }

        $id = (string) Str::uuid();
        $session = (string) (int) (microtime(true) * 1000);
        $startsAt = $this->allocateWindow($prefix);

        $import = [
            'id' => $id,
            'prefix' => $prefix,
            'session' => $session,
            'starts_at' => $startsAt->toIso8601String(),
            'title' => $meta['title'],
            'slug' => $meta['slug'] ?? null,
            'description' => $meta['description'] ?? null,
            'date' => $meta['date'] ?? null,
            'show_id' => $meta['show_id'] ?? null,
            'source_id' => $meta['source_id'] ?? null,
            'required_roles' => $meta['required_roles'] ?? [],
            'committed_recording_id' => null,
        ];

        Cache::put(self::CACHE_PREFIX.$id, $import, now()->addHours(self::TTL_HOURS));

        return [
            'import' => $import,
            'recipe' => ArchiveLadder::recipe(),
            // Everything the client needs to name a file, so naming is not a convention it
            // has to remember and we get to change it without reissuing binaries.
            'segment_name' => "{$prefix}_{rendition}_{$session}_{number}.ts",
            'expires_at' => now()->addHours(self::TTL_HOURS)->toIso8601String(),
        ];
    }

    /**
     * Where this import's segments start.
     *
     * A prefix nothing has ever been imported under starts at the top of the current hour;
     * otherwise one clear hour after the last segment the archive holds for it. The gap is
     * deliberate: adjacent imports sharing an hour bucket would still resolve, but a cut
     * whose end marker slipped would silently pick up the next import's opening segments.
     */
    protected function allocateWindow(string $prefix): CarbonImmutable
    {
        $range = $this->playlists->availableRange($prefix);

        if (($range['to'] ?? null) === null) {
            return CarbonImmutable::now('UTC')->startOfHour();
        }

        return CarbonImmutable::parse($range['to'])->utc()->startOfHour()->addHours(2);
    }

    /**
     * Upload URLs for segments of one import.
     *
     * Every key is rebuilt from the import rather than taken from the request, so the only
     * thing a caller controls is which segment number and rendition it wants to write. An
     * API key therefore cannot reach another import's window, another prefix, or anything
     * else in the bucket.
     *
     * @param  array<int, array{rendition: string, number: int, hour: string}>  $segments
     * @return array<int, array{key: string, url: string}>
     */
    public function presign(array $import, array $segments): array
    {
        $renditions = array_keys(ArchiveLadder::renditions());
        $urls = [];

        foreach ($segments as $segment) {
            $rendition = $segment['rendition'];

            if (! in_array($rendition, $renditions, true)) {
                throw new \InvalidArgumentException("Unknown rendition [{$rendition}].");
            }

            $key = $this->segmentKey($import, $rendition, (int) $segment['number'], $segment['hour']);

            $signed = Storage::disk($this->disk)->temporaryUploadUrl($key, now()->addHours(6));

            // Headers travel with the URL because a signature can cover them: a PUT that
            // omits one the signature includes is rejected, and the client has no way to
            // know which those are.
            $urls[] = [
                'key' => $key,
                'url' => $signed['url'],
                // Flattened to one string per header. The SDK answers in the PSR-7 shape,
                // a list per header, which reads as a type error in any client that
                // expects what an HTTP header actually is.
                'headers' => collect($signed['headers'] ?? [])
                    ->map(fn ($value) => is_array($value) ? implode(', ', $value) : (string) $value)
                    ->all(),
            ];
        }

        return $urls;
    }

    /**
     * Finish an import: write the hour indexes, check the upload landed, create the cut.
     *
     * @param  array<int, array{number: int, duration: float}>  $segments
     */
    public function commit(array $import, array $segments, array $renditions): Recording
    {
        // Commit is the one request in this flow that does real work: it lists every hour
        // the import touched to check the objects landed, writes the indexes, then builds
        // the cut. Listing is the slow part and it is the bucket's speed, not ours - an
        // hour holding 5400 objects has taken 13 seconds to enumerate, so a 65 minute
        // import ran past PHP's 30 second default and answered 500 with every segment
        // already safely uploaded.
        //
        // Raised rather than moved to a queue because the work is seconds when the bucket
        // is behaving, and a synchronous answer is what lets the client report the
        // recording it just created. A multi-hour import is the case to revisit it for.
        @set_time_limit(600);

        if ($import['committed_recording_id'] !== null) {
            throw new \RuntimeException('This import has already been committed.');
        }

        if ($segments === []) {
            throw new \InvalidArgumentException('An import needs at least one segment.');
        }

        $known = array_keys(ArchiveLadder::renditions());
        foreach ($renditions as $rendition) {
            if (! in_array($rendition, $known, true)) {
                throw new \InvalidArgumentException("Unknown rendition [{$rendition}].");
            }
        }

        usort($segments, fn ($a, $b) => $a['number'] <=> $b['number']);

        $prefix = $import['prefix'];
        $pdt = CarbonImmutable::parse($import['starts_at'])->utc();
        $startsAt = $pdt;

        /** @var array<string, string> $indexes hour => body */
        $indexes = [];
        /** @var array<string, array<int, string>> $expected hour => keys */
        $expected = [];

        foreach ($segments as $seq => $segment) {
            $number = (int) $segment['number'];
            $duration = (float) $segment['duration'];

            if ($duration <= 0) {
                throw new \InvalidArgumentException("Segment {$number} reports a duration of {$duration}.");
            }

            $hour = $pdt->format('Ymd/H');

            if (! isset($indexes[$hour])) {
                $indexes[$hour] = "#EXTM3U\n#EXT-X-VERSION:6\n#EXT-X-TARGETDURATION:"
                    .ArchiveLadder::SEGMENT_SECONDS."\n#EXT-X-INDEPENDENT-SEGMENTS\n";
            }

            // The opening discontinuity marks where this import's timeline begins, the same
            // way a publisher session does. ArchivePlaylistService drops it when the segment
            // is first in a cut, so it costs a viewer nothing.
            if ($seq === 0) {
                $indexes[$hour] .= "#EXT-X-DISCONTINUITY\n#EXT-X-ARCHIVE-SESSION:{$import['session']}\n";
            }

            $indexes[$hour] .= "#EXT-X-ARCHIVE-SEQ:{$seq}\n"
                .'#EXT-X-ARCHIVE-OBSERVED:'.$this->stamp(CarbonImmutable::now('UTC'))."\n"
                .sprintf("#EXTINF:%.6f,\n", $duration)
                .'#EXT-X-PROGRAM-DATE-TIME:'.$this->stamp($pdt)."\n"
                .$this->segmentName($import, '%v', $number)."\n";

            foreach ($renditions as $rendition) {
                $expected[$hour][] = $this->segmentKey($import, $rendition, $number, $hour);
            }

            $pdt = $pdt->addSeconds($duration);
        }

        $this->assertUploaded($expected);

        foreach ($indexes as $hour => $body) {
            Storage::disk($this->disk)->put(
                self::ARCHIVE_PREFIX."/{$prefix}/{$hour}/index.m3u8",
                $body,
            );
        }

        $recording = Recording::create([
            'show_id' => $import['show_id'],
            'source_id' => $import['source_id'],
            'title' => $import['title'],
            'slug' => $import['slug'] ?: null,
            'description' => $import['description'],
            'date' => $import['date'] ?: $startsAt,
            'starts_at' => $startsAt,
            'ends_at' => $pdt,
            'archive_prefix' => self::ARCHIVE_PREFIX."/{$prefix}",
            'status' => 'draft',
            'is_published' => false,
            'required_roles' => $import['required_roles'] ?: null,
        ]);

        // Same call the manage panel makes after a trim: the cut is derived from the
        // archive, so building it is reading back what was just written rather than
        // trusting the numbers the client sent.
        $this->playlists->build($recording->fresh());

        $import['committed_recording_id'] = $recording->id;
        Cache::put(self::CACHE_PREFIX.$import['id'], $import, now()->addHours(self::TTL_HOURS));

        return $recording->fresh();
    }

    /**
     * Every segment the index promises has to exist before the index is written.
     *
     * A cut that references a missing object fails at playback, on a viewer's machine,
     * long after the importer has gone home. Listing an hour is a handful of paginated
     * requests, which is cheap for a check that runs once per import.
     *
     * @param  array<string, array<int, string>>  $expected
     */
    protected function assertUploaded(array $expected): void
    {
        foreach ($expected as $hour => $keys) {
            $directory = dirname($keys[0]);
            $present = array_flip(Storage::disk($this->disk)->files($directory));

            $missing = array_values(array_filter($keys, fn (string $key) => ! isset($present[$key])));

            if ($missing !== []) {
                $sample = implode(', ', array_slice($missing, 0, 3));

                throw new \RuntimeException(
                    count($missing)." segment(s) of hour {$hour} are not in the bucket: {$sample}"
                    .(count($missing) > 3 ? ', ...' : '')
                );
            }
        }
    }

    public function find(string $id): ?array
    {
        $import = Cache::get(self::CACHE_PREFIX.$id);

        return is_array($import) ? $import : null;
    }

    protected function segmentName(array $import, string $rendition, int $number): string
    {
        return sprintf('%s_%s_%s_%06d.ts', $import['prefix'], $rendition, $import['session'], $number);
    }

    protected function segmentKey(array $import, string $rendition, int $number, string $hour): string
    {
        if (! preg_match('/^\d{8}\/\d{2}$/', $hour)) {
            throw new \InvalidArgumentException("Malformed archive hour [{$hour}].");
        }

        // An import may only write inside the window it was given. Without this an API key
        // could scatter objects across a prefix's whole history - not destructive, since
        // the session id keeps names unique, but it would leave segments no index knows
        // about and no reaper collects.
        $start = CarbonImmutable::parse($import['starts_at'])->utc()->startOfHour();
        $hourStart = CarbonImmutable::createFromFormat('Ymd/H', $hour, 'UTC')->startOfHour();

        if ($hourStart < $start || $hourStart > $start->addHours(self::MAX_IMPORT_HOURS)) {
            throw new \InvalidArgumentException("Archive hour [{$hour}] is outside this import's window.");
        }

        return self::ARCHIVE_PREFIX."/{$import['prefix']}/{$hour}/".$this->segmentName($import, $rendition, $number);
    }

    protected function stamp(CarbonImmutable $moment): string
    {
        return $moment->format('Y-m-d\TH:i:s.v').'+0000';
    }
}
