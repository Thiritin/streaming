<?php

namespace App\Services;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * How full the archive bucket is.
 *
 * S3 has no "free space" call, and none of the providers this runs against expose a
 * quota over the API, so the only honest answer is: add up what is stored, and compare
 * it to a limit an operator states in config. Without ARCHIVE_QUOTA_BYTES the panel can
 * still report what is used - it just cannot report what is left, and says so rather
 * than inventing a denominator.
 *
 * Adding it up means a full listing, and a con-long event puts hundreds of thousands of
 * two second segments in the bucket. That is minutes of paginated requests, far too slow
 * for a page load, so the result is computed on a schedule and served from the cache with
 * the time it was taken attached. A stale number labelled stale beats a fresh number that
 * costs a 30 second page render.
 */
class ArchiveStorageService
{
    public const CACHE_KEY = 'archive.storage.usage';

    /**
     * Pages of 1000 keys before the scan gives up.
     *
     * A backstop against a bucket that has grown past what this approach can measure,
     * not a tuning knob: at 2s segments and four renditions, 5000 pages is several
     * months of continuous archive. A truncated scan reports itself as partial, because
     * a total that silently stopped counting reads as "we have plenty of room left".
     */
    protected const MAX_PAGES = 5000;

    protected string $disk;

    public function __construct(?string $disk = null)
    {
        $this->disk = $disk ?? config('stream.archive_disk', 'dvr');
    }

    /**
     * The last completed scan, or a null result when none has run yet.
     *
     * Never scans on demand. The listing is a background job's work; a controller that
     * fell back to doing it inline would hang the recordings page for minutes the first
     * time anyone opened it after a cache flush.
     *
     * @return array{
     *     configured: bool, scanned_at: string|null, bytes: int|null, objects: int|null,
     *     quota: int|null, free: int|null, percent: float|null, partial: bool,
     *     error: string|null, prefixes: array<int, array{label: string, bytes: int, objects: int}>
     * }
     */
    public function usage(): array
    {
        $cached = Cache::get(self::CACHE_KEY);

        if (! is_array($cached)) {
            return $this->empty();
        }

        return $cached + $this->empty();
    }

    /**
     * Walk the bucket and cache the totals. Minutes, not seconds - queue it.
     */
    public function refresh(): array
    {
        $result = $this->scan();

        Cache::forever(self::CACHE_KEY, $result);

        return $result;
    }

    public function quota(): ?int
    {
        $quota = (int) config('stream.archive_quota_bytes', 0);

        return $quota > 0 ? $quota : null;
    }

    protected function scan(): array
    {
        $quota = $this->quota();
        $bucket = config("filesystems.disks.{$this->disk}.bucket");
        $disk = Storage::disk($this->disk);

        if (! $bucket || ! $this->supportsListing($disk)) {
            return ['error' => "The [{$this->disk}] disk is not an S3 bucket, so it cannot be measured."] + $this->empty();
        }

        $bytes = 0;
        $objects = 0;
        $partial = false;

        /** @var array<string, array{bytes: int, objects: int}> $groups */
        $groups = [];

        try {
            $pages = $disk->getClient()->getPaginator('ListObjectsV2', [
                'Bucket' => $bucket,
            ]);

            $page = 0;

            foreach ($pages as $result) {
                if (++$page > self::MAX_PAGES) {
                    $partial = true;
                    break;
                }

                foreach ($result['Contents'] ?? [] as $object) {
                    $size = (int) ($object['Size'] ?? 0);
                    $label = $this->group((string) ($object['Key'] ?? ''));

                    $bytes += $size;
                    $objects++;

                    $groups[$label]['bytes'] = ($groups[$label]['bytes'] ?? 0) + $size;
                    $groups[$label]['objects'] = ($groups[$label]['objects'] ?? 0) + 1;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Archive storage scan failed: '.$e->getMessage());

            return ['error' => $e->getMessage()] + $this->empty();
        }

        uasort($groups, fn (array $a, array $b) => $b['bytes'] <=> $a['bytes']);

        return [
            'configured' => true,
            'scanned_at' => now()->toIso8601String(),
            'bytes' => $bytes,
            'objects' => $objects,
            'quota' => $quota,
            'free' => $quota === null ? null : max(0, $quota - $bytes),
            'percent' => $quota === null ? null : round($bytes / $quota * 100, 1),
            'partial' => $partial,
            'error' => null,
            'prefixes' => array_map(
                fn (string $label, array $totals) => ['label' => $label] + $totals,
                array_keys($groups),
                array_values($groups),
            ),
        ];
    }

    /**
     * What a key counts towards.
     *
     * Segments are grouped by source rather than lumped under `archive/`, because the
     * question behind the panel is always which source is eating the bucket, and a
     * per-source stream that ran all week answers it on sight.
     */
    protected function group(string $key): string
    {
        $parts = explode('/', $key);

        if ($parts[0] === 'archive' && isset($parts[1])) {
            return 'archive/'.$parts[1];
        }

        return count($parts) > 1 ? $parts[0].'/' : '(root)';
    }

    protected function supportsListing(Filesystem $disk): bool
    {
        return $disk instanceof AwsS3V3Adapter;
    }

    /**
     * @return array<string, mixed>
     */
    protected function empty(): array
    {
        return [
            'configured' => false,
            'scanned_at' => null,
            'bytes' => null,
            'objects' => null,
            'quota' => $this->quota(),
            'free' => null,
            'percent' => null,
            'partial' => false,
            'error' => null,
            'prefixes' => [],
        ];
    }
}
