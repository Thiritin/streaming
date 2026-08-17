<?php

namespace App\Console\Commands;

use App\Models\Recording;
use App\Services\ArchivePlaylistService;
use App\Services\ArchiveStorageService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Number;

class ArchiveUsage extends Command
{
    protected $signature = 'archive:usage
                            {--refresh : Rescan the bucket instead of reading the cached totals}
                            {--recordings : Also recompute the cached size of every built recording}';

    protected $description = 'Report how full the archive bucket is, and what is taking up the room';

    public function handle(ArchiveStorageService $storage, ArchivePlaylistService $playlists): int
    {
        if ($this->option('recordings')) {
            $this->measureRecordings($playlists);
        }

        if ($this->option('refresh')) {
            $this->info('Scanning the bucket, this takes a while...');
            $usage = $storage->refresh();
        } else {
            $usage = $storage->usage();
        }

        if ($usage['error']) {
            $this->error($usage['error']);

            return self::FAILURE;
        }

        if (! $usage['configured']) {
            $this->warn('No scan has run yet. Use --refresh, or wait for the scheduled one.');

            return self::SUCCESS;
        }

        $rows = [
            ['Used', Number::fileSize($usage['bytes'], 2)],
            ['Objects', Number::format($usage['objects'])],
            ['Quota', $usage['quota'] ? Number::fileSize($usage['quota'], 2) : 'not set (ARCHIVE_QUOTA_BYTES)'],
            ['Free', $usage['free'] === null ? '-' : Number::fileSize($usage['free'], 2)],
            ['In use', $usage['percent'] === null ? '-' : $usage['percent'].'%'],
            ['Scanned', $usage['scanned_at']],
        ];

        $this->table(['', ''], $rows);

        if ($usage['partial']) {
            $this->warn('The listing hit its page cap, so these totals are a floor, not a total.');
        }

        $this->table(
            ['Prefix', 'Size', 'Objects'],
            array_map(fn (array $p) => [
                $p['label'],
                Number::fileSize($p['bytes'], 2),
                Number::format($p['objects']),
            ], $usage['prefixes']),
        );

        return self::SUCCESS;
    }

    /**
     * Backfill `archive_bytes` for cuts built before the column existed.
     *
     * Reads hour indexes rather than rebuilding, so it never touches a recording's
     * markers, playlist or status - a size backfill must not be able to break playback.
     */
    protected function measureRecordings(ArchivePlaylistService $playlists): void
    {
        $recordings = Recording::query()
            ->whereNotNull('starts_at')
            ->whereNotNull('ends_at')
            ->get();

        $this->info("Measuring {$recordings->count()} recordings...");

        foreach ($recordings as $recording) {
            $source = $recording->archiveSourceSlug();

            if (! $source) {
                continue;
            }

            try {
                $bytes = $playlists->cutBytes(
                    $source,
                    CarbonImmutable::parse($recording->starts_at)->utc(),
                    CarbonImmutable::parse($recording->ends_at)->utc(),
                );
            } catch (\Throwable $e) {
                $this->warn("  {$recording->slug}: {$e->getMessage()}");

                continue;
            }

            $recording->forceFill(['archive_bytes' => $bytes])->save();
            $this->line("  {$recording->slug}: ".Number::fileSize($bytes, 2));
        }
    }
}
