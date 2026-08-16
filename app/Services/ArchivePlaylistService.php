<?php

namespace App\Services;

use App\Models\Recording;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Builds VOD playlists by selecting a range of segments out of a source's archive.
 *
 * The archive uploader writes one HLS playlist per source per hour, which is the durable
 * record of what the sliding live playlist used to say. Cutting a recording is therefore
 * playlist authoring, not media processing: no decode, no concat, no -ss, no re-encode.
 * Nothing is ever cut inside a segment, so the seams that plagued the MP4 pipeline cannot
 * occur by construction.
 *
 * Because the output is derived and the archive is truth, build() is idempotent and safe
 * to run any number of times. Adjusting a marker a month later is just another build.
 *
 * See docs/dvr-archive-plan.md.
 */
class ArchivePlaylistService
{
    /** Renditions in ascending quality, matching the transcoder's var_stream_map. */
    protected const RENDITIONS = [
        'sd' => ['bandwidth' => 1_500_000, 'resolution' => '854x480'],
        'hd' => ['bandwidth' => 3_500_000, 'resolution' => '1280x720'],
        'fhd' => ['bandwidth' => 6_000_000, 'resolution' => '1920x1080'],
    ];

    /**
     * The publisher's own bitstream, archived but never served live.
     *
     * Not part of RENDITIONS because almost nothing about it is shared with the
     * ladder. It is cut on the publisher's keyframes rather than the ladder's
     * forced 2s marks, so it has its own hour index; its bitrate and resolution are
     * whatever the encoder on the con floor was set to, so they are measured from
     * the index rather than declared here; and it is deliberately absent from the
     * master unless an operator asks for it, because handing hls.js a 17 Mbps rung
     * means every viewer on a fast connection pulls the archive's full contribution
     * bitrate out of S3.
     *
     * It is always reachable explicitly at /archive/{slug}/source.m3u8.
     */
    public const SOURCE_RENDITION = 'source';

    protected const ARCHIVE_PREFIX = 'archive';

    protected const RECORDINGS_PREFIX = 'recordings';

    protected string $disk;

    public function __construct(?string $disk = null)
    {
        $this->disk = $disk ?? config('stream.archive_disk', 'dvr');
    }

    /**
     * Resolve a recording's cut and cache what the listing needs.
     *
     * Deliberately does not write playlists anywhere. Segments are served through
     * presigned URLs, which expire, so a stored playlist would be dead 24 hours after it
     * was written. Playlists are rendered per request instead (see renderMaster and
     * renderMedia), which also puts the access check on the request that hands out the
     * URLs rather than on a static object anyone could fetch.
     *
     * What is stored is only what a listing needs without touching S3: duration, segment
     * count, and whether the range resolves at all.
     */
    public function build(Recording $recording): void
    {
        if (! $recording->starts_at || ! $recording->ends_at) {
            throw new \RuntimeException('Recording has no cut range set.');
        }

        if ($recording->ends_at <= $recording->starts_at) {
            throw new \RuntimeException('Recording ends before it starts.');
        }

        $source = $recording->archiveSourceSlug();
        if (! $source) {
            throw new \RuntimeException('Recording is not attached to a source.');
        }

        // Normalised to UTC before anything else touches them. The archive is bucketed by
        // UTC hour, and a marker that arrives as a naive local string (a datetime-local
        // input, or a bare string in a seeder) is interpreted in the app timezone, which
        // silently shifts it into an hour bucket that holds no segments. The failure then
        // reads as "no archived segments cover that range" rather than as a timezone bug.
        $segments = $this->segmentsInRange(
            $source,
            CarbonImmutable::parse($recording->starts_at)->utc(),
            CarbonImmutable::parse($recording->ends_at)->utc(),
        );

        if ($segments === []) {
            throw new \RuntimeException(
                'No archived segments cover that range. The archive may have expired, '
                .'or the segments may not be uploaded yet.'
            );
        }

        $recording->forceFill([
            // The app renders the playlist, so this is a route rather than an S3 object.
            'm3u8_url' => route('recordings.playlist.master', $recording->slug),
            'duration' => (int) round(array_sum(array_column($segments, 'duration'))),
            'segment_count' => count($segments),
            'status' => 'ready',
            'build_error' => null,
            'playlist_built_at' => now(),
        ])->save();
    }

    /**
     * Master playlist, listing the renditions the archive actually holds for this cut.
     */
    public function renderMaster(Recording $recording): string
    {
        $lines = ['#EXTM3U', '#EXT-X-VERSION:6', '#EXT-X-INDEPENDENT-SEGMENTS'];

        foreach (self::RENDITIONS as $rendition => $meta) {
            $lines[] = sprintf(
                '#EXT-X-STREAM-INF:BANDWIDTH=%d,RESOLUTION=%s,CODECS="avc1.64001f,mp4a.40.2"',
                $meta['bandwidth'],
                $meta['resolution'],
            );
            $lines[] = route('recordings.playlist.media', [$recording->slug, $rendition]);
        }

        // Opt-in, and last, so a player that ignores BANDWIDTH ordering still starts
        // on the ladder. RESOLUTION is omitted rather than guessed: it is optional in
        // RFC 8216, and a wrong value is worse than none because players use it to
        // pick a rung.
        if ($this->sourceInMaster() && ($bandwidth = $this->sourceBandwidth($recording)) !== null) {
            $lines[] = sprintf('#EXT-X-STREAM-INF:BANDWIDTH=%d', $bandwidth);
            $lines[] = route('recordings.playlist.media', [$recording->slug, self::SOURCE_RENDITION]);
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * Whether the original-quality rendition is advertised in the master playlist.
     *
     * Off by default. The archive holds it either way and it can always be played
     * explicitly; what this controls is whether ABR is allowed to select it, which
     * is a bandwidth bill rather than an availability question.
     */
    public function sourceInMaster(): bool
    {
        return (bool) config('stream.archive_source_in_master', false);
    }

    /**
     * Average bitrate of the source rendition over this cut, from the byte counts
     * the uploader records per segment.
     *
     * Measured rather than configured: the whole point of the rendition is that
     * nobody here decided what the publisher would send. Null when the archive
     * holds no source segments for the range, which is also the signal not to
     * advertise the rung at all.
     */
    public function sourceBandwidth(Recording $recording): ?int
    {
        if (! $recording->hasCut()) {
            return null;
        }

        $segments = $this->segmentsInRange(
            $recording->archiveSourceSlug(),
            CarbonImmutable::parse($recording->starts_at)->utc(),
            CarbonImmutable::parse($recording->ends_at)->utc(),
            self::SOURCE_RENDITION,
        );

        $duration = array_sum(array_column($segments, 'duration'));
        $bytes = array_sum(array_column($segments, 'bytes'));

        if ($duration <= 0 || $bytes <= 0) {
            return null;
        }

        return (int) round($bytes * 8 / $duration);
    }

    /**
     * Media playlist for one rendition, with a presigned URL per segment.
     */
    public function renderMedia(Recording $recording, string $rendition): string
    {
        $this->assertRendition($rendition);

        $source = $this->assertArchiveSource($recording);

        $segments = $this->segmentsInRange(
            $source,
            CarbonImmutable::parse($recording->starts_at)->utc(),
            CarbonImmutable::parse($recording->ends_at)->utc(),
            $rendition,
        );

        return $this->renderMediaPlaylist($segments, $source, $rendition);
    }

    public function renditions(): array
    {
        return [...array_keys(self::RENDITIONS), self::SOURCE_RENDITION];
    }

    protected function assertRendition(string $rendition): void
    {
        if (! in_array($rendition, $this->renditions(), true)) {
            throw new \InvalidArgumentException("Unknown rendition [{$rendition}].");
        }
    }

    /**
     * The archive a recording reads from, or a clear refusal.
     *
     * Recordings registered from outside carry a hand-entered `m3u8_url` and no source,
     * so `archiveSourceSlug()` is null for them and they have no archive to render. That
     * used to fall through to `segmentsInRange(string $source, ...)` and die on a
     * TypeError - which `RecordingPlaylistController` then handed to the client as the
     * body of a 410, absolute server path included. Refuse here, in the vocabulary of
     * the problem, so the caller has something safe to say.
     */
    protected function assertArchiveSource(Recording $recording): string
    {
        $source = $recording->archiveSourceSlug();

        if (! $source) {
            throw new \RuntimeException(
                'This recording is not a cut from a source archive, so it has no '
                .'generated playlist. Its media is at the URL it was registered with.'
            );
        }

        return $source;
    }

    /**
     * Playlist for an arbitrary window of a source's archive, independent of any cut.
     *
     * This is what the trim editor previews. Scrubbing only within the current cut would
     * be useless for the job it exists to do: an operator sets the in point by looking at
     * what happened *before* the current one, so the editor needs to play material outside
     * the markers.
     */
    public function renderRange(
        string $source,
        CarbonImmutable $from,
        CarbonImmutable $to,
        string $rendition,
    ): string {
        $this->assertRendition($rendition);

        $segments = $this->segmentsInRange($source, $from->utc(), $to->utc(), $rendition);

        return $this->renderMediaPlaylist($segments, $source, $rendition);
    }

    /**
     * The instant the first segment of a range starts.
     *
     * The editor maps the video element's currentTime onto wall clock, and a range never
     * begins exactly on the requested boundary: selection is by segment start, so the
     * first segment can begin slightly before `from`. Without this the markers would be
     * off by up to one segment.
     */
    public function rangeStart(string $source, CarbonImmutable $from, CarbonImmutable $to): ?CarbonImmutable
    {
        $segments = $this->segmentsInRange($source, $from->utc(), $to->utc());

        return $segments === [] ? null : $segments[0]['pdt'];
    }

    /**
     * Same as build(), but records the failure on the model rather than throwing, so a
     * controller can report it without the recording ending up in an unclear state.
     */
    public function rebuild(Recording $recording): bool
    {
        try {
            $this->build($recording);

            return true;
        } catch (\Throwable $e) {
            Log::warning("Playlist build failed for recording {$recording->id}: ".$e->getMessage());

            $recording->forceFill([
                'status' => 'failed',
                'build_error' => $e->getMessage(),
            ])->save();

            return false;
        }
    }

    /**
     * Every archived segment whose start falls inside the range.
     *
     * Selection is on the segment's own start, so a segment straddling either boundary is
     * included whole. That is what keeps cuts seamless: the price is that a cut can
     * overshoot its end marker by up to one segment, which is far cheaper than the frame
     * accuracy it buys back.
     */
    public function segmentsInRange(
        string $source,
        CarbonImmutable $from,
        CarbonImmutable $to,
        string $rendition = 'hd',
    ): array {
        $segments = [];

        foreach ($this->hoursBetween($from, $to) as $hour) {
            foreach ($this->readHourIndex($source, $hour, $rendition) as $segment) {
                if ($segment['pdt'] >= $from && $segment['pdt'] < $to) {
                    $segments[] = $segment;
                }
            }
        }

        // Hour files are appended in observation order, but a range spans several of them
        // and the archive sequence is the only ordering that never depends on a clock.
        usort($segments, fn ($a, $b) => $a['seq'] <=> $b['seq']);

        return $segments;
    }

    /**
     * The window an operator can actually cut from: what the archive still holds, and how
     * far it has caught up to live. Drives the "archive available from X to Y" hint, so a
     * cut is not silently truncated at either end.
     */
    public function availableRange(string $source): array
    {
        // Deliberately walks the day/hour prefixes rather than listing the source's
        // objects: `allFiles()` is a recursive listing, and a con-long stream puts
        // ~650k segments under this prefix, so filtering that down to two index
        // files costs hundreds of round trips on a page load. Delimiter listings
        // touch four prefixes regardless of how much is archived.
        $first = $this->edgeHour($source, last: false);
        $last = $this->edgeHour($source, last: true);

        if ($first === null || $last === null) {
            return ['from' => null, 'to' => null];
        }

        $firstIndex = $this->readHourIndex($source, $first);
        $lastIndex = $this->readHourIndex($source, $last);

        return [
            'from' => $firstIndex === [] ? null : $firstIndex[0]['pdt'],
            'to' => $lastIndex === []
                ? null
                : end($lastIndex)['pdt']->addSeconds((int) end($lastIndex)['duration']),
        ];
    }

    /** Earliest or latest `YYYYMMDD/HH` bucket a source has, or null when it has none. */
    protected function edgeHour(string $source, bool $last): ?string
    {
        $pick = function (array $paths) use ($last): ?string {
            if ($paths === []) {
                return null;
            }
            sort($paths);

            return basename($last ? end($paths) : $paths[0]);
        };

        $day = $pick(Storage::disk($this->disk)->directories(self::ARCHIVE_PREFIX."/{$source}"));

        if ($day === null) {
            return null;
        }

        $hour = $pick(Storage::disk($this->disk)->directories(self::ARCHIVE_PREFIX."/{$source}/{$day}"));

        return $hour === null ? null : "{$day}/{$hour}";
    }

    /** Hour buckets touched by a range, as YYYYMMDD/HH. */
    protected function hoursBetween(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $hours = [];
        $cursor = $from->utc()->startOfHour();
        $end = $to->utc();

        while ($cursor <= $end) {
            $hours[] = $cursor->format('Ymd/H');
            $cursor = $cursor->addHour();
        }

        return $hours;
    }

    /**
     * The ladder shares one index because its three renditions are cut at identical
     * instants; the source rendition is cut on the publisher's keyframes and so has
     * its own. Everything downstream is the same shape.
     */
    protected function readHourIndex(string $source, string $hour, string $rendition = 'hd'): array
    {
        $name = $rendition === self::SOURCE_RENDITION ? 'index-source.m3u8' : 'index.m3u8';
        $path = self::ARCHIVE_PREFIX."/{$source}/{$hour}/{$name}";

        if (! Storage::disk($this->disk)->exists($path)) {
            return [];
        }

        return $this->parseHourIndex(Storage::disk($this->disk)->get($path));
    }

    /**
     * Parses an hour index written by archive_uploader.py.
     *
     * Alongside the standard tags it carries #EXT-X-ARCHIVE-SEQ (monotonic ordering,
     * assigned on observation and never clock-derived) and #EXT-X-ARCHIVE-OBSERVED (the
     * origin's own wall clock, so drift between the publisher's timeline and real time
     * stays measurable after the fact).
     */
    public function parseHourIndex(string $contents): array
    {
        $segments = [];
        $duration = null;
        $pdt = null;
        $observed = null;
        $seq = null;
        $bytes = null;
        $discontinuity = false;

        foreach (preg_split('/\R/', $contents) as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            if (str_starts_with($line, '#EXT-X-ARCHIVE-SEQ:')) {
                $seq = (int) substr($line, 19);
            } elseif (str_starts_with($line, '#EXT-X-ARCHIVE-OBSERVED:')) {
                $observed = $this->parseTimestamp(substr($line, 24));
            } elseif (str_starts_with($line, '#EXT-X-ARCHIVE-BYTES:')) {
                // Only the source rendition carries this. The ladder's bitrates are
                // constants the transcoder was told to hit; the publisher's is not.
                $bytes = (int) substr($line, 21);
            } elseif (str_starts_with($line, '#EXTINF:')) {
                $duration = (float) strtok(substr($line, 8), ',');
            } elseif (str_starts_with($line, '#EXT-X-PROGRAM-DATE-TIME:')) {
                $pdt = $this->parseTimestamp(substr($line, 25));
            } elseif ($line === '#EXT-X-DISCONTINUITY') {
                $discontinuity = true;
            } elseif (! str_starts_with($line, '#')) {
                if ($pdt !== null && $duration !== null) {
                    $segments[] = [
                        'name' => $line,
                        'duration' => $duration,
                        'pdt' => $pdt,
                        'observed' => $observed,
                        'seq' => $seq ?? count($segments),
                        'bytes' => $bytes,
                        'discontinuity' => $discontinuity,
                    ];
                }
                $duration = null;
                $pdt = null;
                $observed = null;
                $seq = null;
                $bytes = null;
                $discontinuity = false;
            }
        }

        return $segments;
    }

    protected function parseTimestamp(string $value): ?CarbonImmutable
    {
        try {
            return CarbonImmutable::parse(trim($value))->utc();
        } catch (\Throwable) {
            return null;
        }
    }

    protected function renderMediaPlaylist(array $segments, string $source, string $rendition): string
    {
        // Reachable from the request path, not just from build(): a cut whose hours have
        // since expired out of the archive resolves to nothing. Raise something that says
        // so, rather than letting max() fail on an empty array.
        if ($segments === []) {
            throw new \RuntimeException(
                'No archived segments cover this recording any more. The archive it was '
                .'cut from has most likely expired.'
            );
        }

        $target = (int) ceil(max(array_column($segments, 'duration')));

        $lines = [
            '#EXTM3U',
            '#EXT-X-VERSION:6',
            "#EXT-X-TARGETDURATION:{$target}",
            '#EXT-X-MEDIA-SEQUENCE:0',
            // Without both of these a player treats the playlist as live and refuses to
            // expose a full seek bar.
            '#EXT-X-PLAYLIST-TYPE:VOD',
            '#EXT-X-INDEPENDENT-SEGMENTS',
        ];

        foreach ($segments as $i => $segment) {
            // The leading discontinuity every session opens with is meaningless once the
            // segment is the first thing in the cut.
            if ($segment['discontinuity'] && $i > 0) {
                $lines[] = '#EXT-X-DISCONTINUITY';
            }

            $lines[] = '#EXT-X-PROGRAM-DATE-TIME:'.$segment['pdt']->format('Y-m-d\TH:i:s.vP');
            $lines[] = sprintf('#EXTINF:%.6f,', $segment['duration']);
            $lines[] = $this->segmentUrl($source, $segment, $rendition);
        }

        $lines[] = '#EXT-X-ENDLIST';

        return implode("\n", $lines)."\n";
    }

    /**
     * Index entries name segments generically, because all renditions are cut at the same
     * instants and one entry therefore describes all three.
     *
     * Presigned rather than public: the archive holds the raw continuous capture of every
     * source, which includes material that was never published and everything an operator
     * trimmed off. A signed URL grants access to one object for a bounded time, so the
     * bucket itself stays private.
     */
    protected function segmentUrl(string $source, array $segment, string $rendition): string
    {
        $name = str_replace('%v', $rendition, $segment['name']);
        $hour = $segment['pdt']->format('Ymd/H');
        $path = self::ARCHIVE_PREFIX."/{$source}/{$hour}/{$name}";

        // See config/stream.php for why local development cannot use signed URLs.
        if (config('stream.archive_url_mode') === 'proxy') {
            return route('archive.segment', ['path' => $path]);
        }

        return Storage::disk($this->disk)->temporaryUrl(
            $path,
            now()->addSeconds(self::signedUrlLifetime()),
        );
    }

    /**
     * How long a segment URL stays valid.
     *
     * Long enough that a viewer never hits an expiry mid-playback, since a playlist is
     * fetched once at the start of a VOD session rather than refreshed like a live one.
     * The trade is explicit: a leaked playlist grants access to those segments until the
     * signatures lapse.
     */
    public static function signedUrlLifetime(): int
    {
        return (int) config('stream.archive_url_ttl', 86400);
    }
}
