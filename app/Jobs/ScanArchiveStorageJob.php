<?php

namespace App\Jobs;

use App\Services\ArchiveStorageService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Refresh the cached archive bucket totals.
 *
 * Unique, because the scan is a full bucket listing that can run for minutes: an
 * operator hitting refresh three times should queue one pass, not three overlapping
 * ones each paying for its own several hundred list calls.
 */
class ScanArchiveStorageJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900;

    public int $tries = 1;

    public int $uniqueFor = 900;

    public function handle(ArchiveStorageService $storage): void
    {
        $storage->refresh();
    }
}
