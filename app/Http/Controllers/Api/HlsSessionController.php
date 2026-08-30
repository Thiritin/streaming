<?php

namespace App\Http\Controllers\Api;

use App\Enum\ServerTypeEnum;
use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Support\CloudSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class HlsSessionController extends Controller
{
    /**
     * Handle heartbeat from edge server with viewer counts
     */
    public function heartbeat(Request $request)
    {
        $request->validate([
            'server_id' => 'required|string',
            'viewer_count' => 'required|integer|min:0',
            'streams' => 'array',
            'timestamp' => 'required|date',
        ]);

        $serverId = $request->input('server_id');
        $viewerCount = $request->input('viewer_count');
        $streams = $request->input('streams', []);

        Log::info('Edge server heartbeat', [
            'server_id' => $serverId,
            'viewer_count' => $viewerCount,
            'streams' => $streams,
        ]);

        // By hostname or by whatever its provider calls it. `hetzner_id` is still read
        // because edges already in the field POST the id they were installed with.
        $server = Server::where('hostname', $serverId)
            ->orWhere('external_id', $serverId)
            ->orWhere('hetzner_id', $serverId)
            ->first();

        if (! $server) {
            // Local development only: the container reports a name nothing was
            // provisioned under, so the one manually managed edge answers for it. It
            // used to key off a `hetzner_id = 'manual'` sentinel; matching every
            // hand-registered server instead would attach a stray heartbeat from
            // anywhere to whichever manual edge happens to sort first.
            if (\App::isLocal() && str_contains($serverId, 'docker')) {
                $server = Server::where('provider', CloudSettings::MANUAL)
                    ->where('type', ServerTypeEnum::EDGE)
                    ->first();
            }

            if (! $server) {
                Log::warning('Unknown edge server in heartbeat', ['server_id' => $serverId]);

                return response()->json(['error' => 'Unknown server'], 404);
            }
        }

        // Update server viewer count
        $server->updateViewerCount($viewerCount);

        // Store stream-specific viewer counts in cache
        foreach ($streams as $streamSlug => $count) {
            Cache::put(
                "stream_viewers:{$streamSlug}:{$server->id}",
                $count,
                now()->addMinutes(2)
            );
        }

        // Calculate total viewers across all edges for each stream
        foreach ($streams as $streamSlug => $count) {
            $this->updateStreamViewerCount($streamSlug);
        }

        return response()->json([
            'status' => 'ok',
            'server' => [
                'id' => $server->id,
                'hostname' => $server->hostname,
            ],
        ]);
    }

    /**
     * Update total viewer count for a stream across all edges
     */
    private function updateStreamViewerCount($streamSlug)
    {
        // Get all edge servers
        $edges = Server::getActiveEdges();

        $totalViewers = 0;
        foreach ($edges as $edge) {
            $count = Cache::get("stream_viewers:{$streamSlug}:{$edge->id}", 0);
            $totalViewers += $count;
        }

        // Store total count
        Cache::put("stream_total_viewers:{$streamSlug}", $totalViewers, now()->addMinutes(2));

        Log::info('Stream viewer count updated', [
            'stream' => $streamSlug,
            'total_viewers' => $totalViewers,
            'edge_count' => $edges->count(),
        ]);
    }
}
