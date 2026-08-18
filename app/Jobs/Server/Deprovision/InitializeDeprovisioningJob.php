<?php

namespace App\Jobs\Server\Deprovision;

use App\Enum\ServerStatusEnum;
use App\Models\Server;
use App\Models\SourceUser;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Take an edge out of rotation, then let the rest of the chain delete it.
 *
 * This used to walk `users` one row at a time, calling assignServerToUser() on each.
 * Because a scheduled job pre-assigned every account in the database whether or not it
 * had ever watched anything, that loop was the size of the user table - 4,076 rows to
 * move 7 actual viewers - and it blew the 60s queue timeout every time. The chain then
 * aborted before the status was written, so the server stayed `active` and the operator
 * saw a Deprovision button that did nothing. Four attempts died that way on 18 Aug 2026.
 *
 * The work is now proportional to who is watching, because that is the only thing an
 * edge actually holds.
 */
class InitializeDeprovisioningJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public readonly Server $server) {}

    public function handle(): void
    {
        // First, not last. The status is what takes the edge out of activeEdges(), so
        // writing it up front means nothing new can be placed here while the teardown
        // runs - and a failure further down leaves a row that still reads
        // `deprovisioning`, which is recoverable, rather than one that reads `active`.
        $this->server->update([
            'status' => ServerStatusEnum::DEPROVISIONING,
        ]);

        // Viewers are pinned by their session row. Releasing the pin in one statement
        // is enough: the next playlist request finds the edge gone from activeEdges()
        // and picks a fresh one, which is the same path a viewer takes when an edge
        // fails outright.
        $released = SourceUser::where('server_id', $this->server->id)
            ->whereNull('left_at')
            ->update(['server_id' => null]);

        // Both caches name this edge: the list it is being removed from, and the
        // per-viewer pins pointing at it. The list is small and rebuilt on read; the
        // pins fall through on their own because placeViewer re-picks when a pinned
        // edge is no longer active.
        Cache::forget('hls_active_edges');

        Log::info('Edge taken out of rotation for deprovisioning', [
            'server_id' => $this->server->id,
            'server_hostname' => $this->server->hostname,
            'sessions_released' => $released,
        ]);
    }
}
