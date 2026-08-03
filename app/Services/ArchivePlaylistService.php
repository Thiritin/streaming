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

    protected const ARCHIVE_PREFIX = 'archive';

    protected const RECORDINGS_PREFIX = 'recordings';

    protected string $disk;

    public function __construct(?string $disk = null)
    {
        $this->disk = $disk ?? config('stream.archive_disk', 'dvr');
    }

    /**
     * Regenerate every playlist for a recording from its current markers.
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

        foreach (array_keys(self::RENDITIONS) as $rendition) {
            Storage::disk($this->disk)->put(
                $this->mediaPlaylistPath($recording, $rendition),
                $this->renderMediaPlaylist($segments, $source, $rendition),
            );
        }

        Storage::disk($this->disk)->put(
            $this->masterPlaylistPath($recording),
            $this->renderMasterPlaylist($recording),
        );

        $recording->forceFill([
            'm3u8_url' => Storage::disk($this->disk)->url($this->masterPlaylistPath($recording)),
            'duration' => (int) round(array_sum(array_column($segments, 'duration'))),
            'segment_count' => count($segments),
            'status' => 'ready',
            'build_error' => null,
            'playlist_built_at' => now(),
        ])->save();
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
    public function segmentsInRange(string $source, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $segments = [];

        foreach ($this->hoursBetween($from, $to) as $hour) {
            foreach ($this->readHourIndex($source, $hour) as $segment) {
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
        $hours = collect(Storage::disk($this->disk)->allFiles(self::ARCHIVE_PREFIX."/{$source}"))
            ->filter(fn ($p) => str_ends_with($p, 'index.m3u8'))
            ->sort()
            ->values();

        if ($hours->isEmpty()) {
            return ['from' => null, 'to' => null];
        }

        $first = $this->parseHourIndex(Storage::disk($this->disk)->get($hours->first()));
        $last = $this->parseHourIndex(Storage::disk($this->disk)->get($hours->last()));

        return [
            'from' => $first === [] ? null : $first[0]['pdt'],
            'to' => $last === [] ? null : end($last)['pdt']->addSeconds((int) end($last)['duration']),
        ];
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

    protected function readHourIndex(string $source, string $hour): array
    {
        $path = self::ARCHIVE_PREFIX."/{$source}/{$hour}/index.m3u8";

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
                        'discontinuity' => $discontinuity,
                    ];
                }
                $duration = null;
                $pdt = null;
                $observed = null;
                $seq = null;
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
     */
    protected function segmentUrl(string $source, array $segment, string $rendition): string
    {
        $name = str_replace('%v', $rendition, $segment['name']);
        $hour = $segment['pdt']->format('Ymd/H');

        return Storage::disk($this->disk)->url(self::ARCHIVE_PREFIX."/{$source}/{$hour}/{$name}");
    }

    protected function renderMasterPlaylist(Recording $recording): string
    {
        $lines = ['#EXTM3U', '#EXT-X-VERSION:6', '#EXT-X-INDEPENDENT-SEGMENTS'];

        foreach (self::RENDITIONS as $rendition => $meta) {
            $lines[] = sprintf(
                '#EXT-X-STREAM-INF:BANDWIDTH=%d,RESOLUTION=%s,CODECS="avc1.64001f,mp4a.40.2"',
                $meta['bandwidth'],
                $meta['resolution'],
            );
            $lines[] = Storage::disk($this->disk)->url($this->mediaPlaylistPath($recording, $rendition));
        }

        return implode("\n", $lines)."\n";
    }

    protected function mediaPlaylistPath(Recording $recording, string $rendition): string
    {
        return self::RECORDINGS_PREFIX."/{$recording->slug}/{$rendition}.m3u8";
    }

    protected function masterPlaylistPath(Recording $recording): string
    {
        return self::RECORDINGS_PREFIX."/{$recording->slug}/master.m3u8";
    }
}
