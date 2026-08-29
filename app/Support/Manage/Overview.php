<?php

namespace App\Support\Manage;

use App\Enum\ServerStatusEnum;
use App\Enum\ServerTypeEnum;
use App\Enum\SourceStatusEnum;
use App\Enum\StreamStatusEnum;
use App\Models\Server;
use App\Models\ServerMetric;
use App\Models\Show;
use App\Models\Source;
use App\Models\SourceUser;
use App\Services\ServerMetricsService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

/**
 * Everything the dashboard and the status strip read.
 *
 * The dashboard at `/manage` is the operator's one screen: capacity, per-server health,
 * an alert list, live viewer numbers and the next few hours of programme. Each block is
 * a separate method so the page can partial-reload just the ones that move.
 *
 * The view-count chart is not here: the Filament widget hardcoded seven September 2023
 * dates, so it is not ported until there is real per-day viewer history to draw.
 */
final class Overview
{
    /**
     * The always-visible header numbers.
     *
     * @return array<string, mixed>
     */
    public function statusStrip(): array
    {
        $edge = Server::query()->where('type', ServerTypeEnum::EDGE);

        $active = (clone $edge)->where('status', ServerStatusEnum::ACTIVE)->count();
        $total = (clone $edge)->whereNot('status', ServerStatusEnum::DELETED)->count();

        return [
            'stream' => Status::stream($this->streamStatus()),
            'liveShows' => Show::live()->count(),
            'edge' => ['active' => $active, 'total' => $total],
            'viewers' => (int) (clone $edge)
                ->where('status', ServerStatusEnum::ACTIVE)
                ->sum('viewer_count'),
        ];
    }

    /**
     * Edge servers grouped by status, one card each (the ServerActive widget).
     *
     * @return array<int, array{label: string, value: int, tone: string}>
     */
    public function edgeServerCards(): array
    {
        return Server::query()
            ->where('type', ServerTypeEnum::EDGE)
            ->whereNot('status', ServerStatusEnum::DELETED)
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->get()
            ->map(function (Server $row) {
                $status = Status::server($row->status);

                return [
                    'label' => 'Edge '.strtolower($status['label']),
                    'value' => (int) $row->aggregate,
                    'tone' => $status['tone'],
                ];
            })
            ->all();
    }

    /**
     * The Capacity widget.
     *
     * @return array<int, array{label: string, value: int, tone: string, hint: string|null}>
     */
    public function capacityCards(): array
    {
        $edge = Server::query()->where('type', ServerTypeEnum::EDGE);

        $maxClients = (int) (clone $edge)->where('status', ServerStatusEnum::ACTIVE)->sum('max_clients');
        $booting = (int) (clone $edge)->where('status', ServerStatusEnum::PROVISIONING)->sum('max_clients');
        // Open sessions with no edge: viewers who asked for a playlist and could not
        // be placed. Counted on the session row, not on accounts - an account that is
        // not watching is not waiting for anything.
        $waiting = SourceUser::whereNull('left_at')->whereNull('server_id')->count();
        $viewers = (int) (clone $edge)->where('status', ServerStatusEnum::ACTIVE)->sum('viewer_count');

        return [
            [
                'label' => 'Max clients',
                'value' => $maxClients,
                'tone' => Status::INFO,
                'hint' => $maxClients > 0 ? round($viewers / $maxClients * 100).'% in use' : null,
            ],
            [
                'label' => 'Booting capacity',
                'value' => $booting,
                'tone' => $booting > 0 ? Status::WARN : Status::IDLE,
                'hint' => null,
            ],
            [
                'label' => 'Waiting users',
                'value' => $waiting,
                'tone' => $waiting > 0 ? Status::DANGER : Status::OK,
                'hint' => 'No server assigned yet',
            ],
        ];
    }

    /**
     * Viewer numbers, live now and where they are sitting.
     *
     * @return array{total: int, peak: int, perSource: array<int, array{name: string, viewers: int, status: array{label: string, tone: string, icon: string|null}}>}
     */
    public function viewers(): array
    {
        $live = Show::live()->get();

        return [
            'total' => (int) Server::query()
                ->where('type', ServerTypeEnum::EDGE)
                ->where('status', ServerStatusEnum::ACTIVE)
                ->sum('viewer_count'),
            'peak' => (int) $live->max('peak_viewer_count'),
            'perSource' => Source::query()
                ->orderByDesc('priority')
                ->orderBy('name')
                ->get()
                ->map(fn (Source $source) => [
                    'name' => $source->name,
                    'viewers' => (int) $source->shows()->where('status', 'live')->sum('viewer_count'),
                    'status' => Status::source($source->status),
                ])
                ->all(),
        ];
    }

    /**
     * One row per server, edge and origin, for the health table.
     *
     * @return array<int, array<string, mixed>>
     */
    public function servers(): array
    {
        return Server::query()
            ->whereNot('status', ServerStatusEnum::DELETED)
            ->orderBy('type')
            ->orderBy('hostname')
            ->get()
            ->map(function (Server $server) {
                $isEdge = $server->type === ServerTypeEnum::EDGE;
                $max = (int) $server->max_clients;
                $viewers = (int) $server->viewer_count;

                return [
                    'id' => $server->id,
                    'hostname' => $server->hostname ?? '(unnamed)',
                    'ip' => $server->ip,
                    'type' => $server->type?->value,
                    'status' => Status::server($server->status),
                    'health' => $isEdge ? Status::health($server->health_status) : null,
                    'healthMessage' => $server->health_check_message,
                    'viewers' => $viewers,
                    'maxClients' => $max,
                    'load' => $isEdge && $max > 0 ? (int) round($viewers / $max * 100) : null,
                    'heartbeat' => $server->last_heartbeat?->diffForHumans(),
                    'heartbeatStale' => $this->isStale($server),
                    'url' => Route::has('manage.servers.show')
                        ? route('manage.servers.show', $server)
                        : null,
                ];
            })
            ->all();
    }

    /**
     * Everything currently wrong, worst first, so the maintainer reads one list
     * instead of inferring problems from four tables.
     *
     * Each carries a `key` that names the condition rather than its wording, so the
     * Telegram digest can tell an alert that is still standing from a new one, and a
     * `sourceId` where the condition is about one room's feed rather than the
     * installation.
     *
     * @return array<int, array{key: string, sourceId: int|null, tone: string, title: string, detail: string|null, url: string|null}>
     */
    public function alerts(): array
    {
        $alerts = [];

        foreach (Source::where('status', SourceStatusEnum::ERROR)->get() as $source) {
            $alerts[] = [
                'key' => "source:{$source->id}:error",
                'sourceId' => $source->id,
                'tone' => Status::DANGER,
                'title' => "Source '{$source->name}' is in error",
                'detail' => 'The encoder connection failed or was rejected.',
                'url' => Route::has('manage.sources.edit') ? route('manage.sources.edit', $source) : null,
            ];
        }

        $servers = Server::query()->whereNot('status', ServerStatusEnum::DELETED)->get();

        foreach ($servers as $server) {
            $name = $server->hostname ?? "server #{$server->id}";

            if ($server->status === ServerStatusEnum::ERROR) {
                $alerts[] = [
                    'key' => "server:{$server->id}:error",
                    'sourceId' => null,
                    'tone' => Status::DANGER,
                    'title' => "Server {$name} is in error",
                    'detail' => $server->health_check_message,
                    'url' => Route::has('manage.servers.show') ? route('manage.servers.show', $server) : null,
                ];

                continue;
            }

            if ($server->health_status === 'unhealthy') {
                $alerts[] = [
                    'key' => "server:{$server->id}:unhealthy",
                    'sourceId' => null,
                    'tone' => Status::DANGER,
                    'title' => "Server {$name} is failing its health check",
                    'detail' => $server->health_check_message,
                    'url' => Route::has('manage.servers.show') ? route('manage.servers.show', $server) : null,
                ];

                continue;
            }

            // Its own key, not the stale one: a rotation that fixes a box has to post a
            // cleared line of its own rather than being folded into the heartbeat's.
            if ($server->credential_rejected_at) {
                $alerts[] = [
                    'key' => "server:{$server->id}:credentials",
                    'sourceId' => null,
                    'tone' => Status::DANGER,
                    'title' => "Server {$name} credentials rejected",
                    'detail' => 'Since '.$server->credential_rejected_at->diffForHumans(),
                    'url' => Route::has('manage.servers.show') ? route('manage.servers.show', $server) : null,
                ];

                continue;
            }

            if ($this->isStale($server)) {
                $alerts[] = [
                    'key' => "server:{$server->id}:stale",
                    'sourceId' => null,
                    'tone' => Status::WARN,
                    'title' => "Server {$name} has not checked in",
                    'detail' => 'Last heartbeat '.($server->last_heartbeat?->diffForHumans() ?? 'never'),
                    'url' => Route::has('manage.servers.show') ? route('manage.servers.show', $server) : null,
                ];
            }
        }

        // Disk. An origin that fills up stops recording, and an edge that fills up stops
        // caching, so this is worth surfacing well before either happens. The sample is
        // read once for the whole set rather than per server.
        foreach ($this->lowDisk($servers) as $alert) {
            $alerts[] = $alert;
        }

        // A live show pushing nothing is the failure an operator most wants to catch early.
        foreach (Show::live()->with('source')->get() as $show) {
            if ($show->source && $show->source->status !== SourceStatusEnum::ONLINE) {
                $alerts[] = [
                    'key' => "show:{$show->id}:source",
                    'sourceId' => $show->source->id,
                    'tone' => Status::DANGER,
                    'title' => "'{$show->title}' is live but its source is not online",
                    'detail' => "Source '{$show->source->name}' is ".($show->source->status?->value ?? 'unknown').'.',
                    'url' => Route::has('manage.shows.edit') ? route('manage.shows.edit', $show) : null,
                ];
            }
        }

        $edge = Server::query()->where('type', ServerTypeEnum::EDGE)->where('status', ServerStatusEnum::ACTIVE);
        $capacity = (int) (clone $edge)->sum('max_clients');
        $viewers = (int) (clone $edge)->sum('viewer_count');

        if ($capacity > 0 && $viewers / $capacity >= 0.9) {
            $alerts[] = [
                'key' => 'capacity:edge',
                'sourceId' => null,
                'tone' => Status::WARN,
                'title' => 'Edge capacity is nearly full',
                'detail' => round($viewers / $capacity * 100)."% of {$capacity} slots in use.",
                'url' => Route::has('manage.servers.index') ? route('manage.servers.index') : null,
            ];
        }

        // Open sessions with no edge: viewers who asked for a playlist and could not
        // be placed. Counted on the session row, not on accounts - an account that is
        // not watching is not waiting for anything.
        $waiting = SourceUser::whereNull('left_at')->whereNull('server_id')->count();

        if ($waiting > 0 && $viewers > 0) {
            $alerts[] = [
                'key' => 'capacity:waiting',
                'sourceId' => null,
                'tone' => Status::WARN,
                'title' => "{$waiting} viewers have no server assigned",
                'detail' => 'They are waiting for an edge server to come up.',
                'url' => Route::has('manage.servers.index') ? route('manage.servers.index') : null,
            ];
        }

        // Danger before warning; the order within a tone is the order found above.
        usort($alerts, fn (array $a, array $b) => $this->severity($a['tone']) <=> $this->severity($b['tone']));

        return $alerts;
    }

    /**
     * What is on air now and what is coming up, so the producer can see the next handover.
     *
     * @return array<int, array<string, mixed>>
     */
    public function schedule(int $hours = 6): array
    {
        $until = now()->addHours($hours);

        return Show::query()
            ->with('source')
            ->where(function ($query) use ($until) {
                $query->where('status', 'live')
                    ->orWhere(fn ($q) => $q
                        ->where('status', 'scheduled')
                        ->whereBetween('scheduled_start', [now()->subMinutes(15), $until]));
            })
            ->orderByRaw("case when status = 'live' then 0 else 1 end")
            ->orderBy('scheduled_start')
            ->get()
            ->map(fn (Show $show) => [
                'id' => $show->id,
                'title' => $show->title,
                'source' => $show->source?->name,
                'sourceStatus' => $show->source ? Status::source($show->source->status) : null,
                'status' => Status::show($show->status),
                'start' => $show->scheduled_start?->format('H:i'),
                'end' => $show->scheduled_end?->format('H:i'),
                'startsIn' => $show->status === 'scheduled' && $show->scheduled_start
                    ? $show->scheduled_start->diffForHumans(['short' => true])
                    : null,
                'viewers' => (int) $show->viewer_count,
                'autoMode' => (bool) $show->auto_mode,
                'url' => Route::has('manage.shows.edit') ? route('manage.shows.edit', $show) : null,
            ])
            ->all();
    }

    /**
     * A server that has not reported in for three heartbeat intervals is treated as
     * missing, matching the window `activeViewers()` uses for viewer sessions.
     */
    /**
     * Servers whose most recent sample shows the root filesystem nearly full.
     *
     * @param  Collection<int, Server>  $servers
     * @return array<int, array<string, mixed>>
     */
    private function lowDisk($servers): array
    {
        if ($servers->isEmpty()) {
            return [];
        }

        // Ordered ascending so keyBy leaves the newest sample per server, and windowed
        // so a server that stopped reporting days ago cannot raise an alert about a
        // disk state nobody can confirm any more.
        $latest = ServerMetric::query()
            ->whereIn('server_id', $servers->pluck('id'))
            ->where('recorded_at', '>=', now()->subMinutes(15))
            ->whereNotNull('disk_total_bytes')
            ->orderBy('recorded_at')
            ->get(['server_id', 'disk_used_bytes', 'disk_total_bytes'])
            ->keyBy('server_id');

        $alerts = [];

        foreach ($servers as $server) {
            $sample = $latest->get($server->id);

            if (! $sample || ! $sample->disk_total_bytes || $sample->disk_used_bytes === null) {
                continue;
            }

            $free = max(0, $sample->disk_total_bytes - $sample->disk_used_bytes);
            $share = $free / $sample->disk_total_bytes * 100;

            if ($share >= 10) {
                continue;
            }

            $name = $server->hostname ?? "server #{$server->id}";

            $alerts[] = [
                'key' => "server:{$server->id}:disk",
                'sourceId' => null,
                'tone' => $share < 5 ? Status::DANGER : Status::WARN,
                'title' => "Server {$name} is running out of disk",
                'detail' => ServerMetricsService::bytes($free).' free of '
                    .ServerMetricsService::bytes($sample->disk_total_bytes)
                    .' ('.round($share).'%).',
                'url' => Route::has('manage.servers.show') ? route('manage.servers.show', $server) : null,
            ];
        }

        return $alerts;
    }

    private function isStale(Server $server): bool
    {
        if ($server->status !== ServerStatusEnum::ACTIVE) {
            return false;
        }

        return $server->last_heartbeat === null
            || $server->last_heartbeat->lt(now()->subMinutes(3));
    }

    private function severity(string $tone): int
    {
        return match ($tone) {
            Status::DANGER => 0,
            Status::WARN => 1,
            default => 2,
        };
    }

    private function streamStatus(): StreamStatusEnum
    {
        return StreamStatusEnum::tryFrom(
            Cache::get('stream.status', static fn () => StreamStatusEnum::OFFLINE->value)
        ) ?? StreamStatusEnum::OFFLINE;
    }
}
