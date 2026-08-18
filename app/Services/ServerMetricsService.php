<?php

namespace App\Services;

use App\Enum\ServerTypeEnum;
use App\Models\Server;
use App\Models\ServerMetric;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The system samples a server reports on heartbeat, shaped for the server page.
 *
 * Two jobs: say what the box is doing right now in units a person reads without
 * converting anything, and give the same figures over a window so "what was it doing
 * at 21:40 last night" has an answer.
 */
class ServerMetricsService
{
    /**
     * Selectable windows, in minutes.
     */
    public const RANGES = [
        '1h' => 60,
        '6h' => 360,
        '24h' => 1440,
        '7d' => 10080,
    ];

    public const DEFAULT_RANGE = '6h';

    /**
     * Samples land once a minute, so an hour is 60 points and a week is 10 080. Both
     * are bucketed down to this, which keeps the payload small and the line readable.
     * Buckets are fixed slices of the window rather than every nth row, so a window
     * with a dead half hour in it renders a gap instead of quietly closing over it.
     */
    private const MAX_POINTS = 120;

    public function range(?string $requested): string
    {
        return $requested !== null && array_key_exists($requested, self::RANGES)
            ? $requested
            : self::DEFAULT_RANGE;
    }

    /**
     * @return array<string, mixed>
     */
    public function forServer(Server $server, ?string $requested = null): array
    {
        $range = $this->range($requested);
        $minutes = self::RANGES[$range];
        $since = now()->subMinutes($minutes);

        $samples = ServerMetric::query()
            ->where('server_id', $server->id)
            ->where('recorded_at', '>=', $since)
            ->orderBy('recorded_at')
            ->get();

        $latest = $server->latestMetric();

        return [
            'range' => $range,
            'ranges' => [
                ['value' => '1h', 'label' => 'Last hour'],
                ['value' => '6h', 'label' => '6 hours'],
                ['value' => '24h', 'label' => '24 hours'],
                ['value' => '7d', 'label' => '7 days'],
            ],
            'has_samples' => $latest !== null,
            'sampled_at' => $latest?->recorded_at?->diffForHumans(),
            'sampled_at_exact' => $latest?->recorded_at?->format('M j, Y H:i:s'),
            'retention_days' => (int) config('stream.server.metrics_retention_days', 30),
            'cards' => $this->cards($server, $latest),
            'charts' => $this->charts($server, $samples, $since, $minutes),
        ];
    }

    /**
     * Right now, in words. Every card degrades to a dash rather than a zero when the
     * server has never reported that field - a box that is not checking in should not
     * read as one sitting idle at 0%.
     *
     * @return array<int, array<string, mixed>>
     */
    private function cards(Server $server, ?ServerMetric $latest): array
    {
        $cards = [];

        if ($server->type === ServerTypeEnum::EDGE) {
            $capacity = $server->max_clients > 0
                ? round($server->viewer_count / $server->max_clients * 100).'% of '.$server->max_clients.' max'
                : null;

            $cards[] = [
                'key' => 'viewers',
                'label' => 'Viewers',
                'value' => number_format((int) $server->viewer_count, 0, '.', ' '),
                'hint' => $capacity,
                'tone' => $this->tone($server->max_clients > 0 ? $server->viewer_count / $server->max_clients * 100 : 0),
            ];
        }

        $cpu = $latest?->cpu_percent;
        $cards[] = [
            'key' => 'cpu',
            'label' => 'CPU',
            'value' => $cpu === null ? '—' : $this->percent($cpu),
            'hint' => $latest?->load_1 === null
                ? null
                : 'Load '.number_format($latest->load_1, 2).($latest->cpu_cores ? ' across '.$latest->cpu_cores.' cores' : ''),
            'tone' => $this->tone($cpu),
        ];

        $memoryPercent = $this->share($latest?->memory_used_bytes, $latest?->memory_total_bytes);
        $cards[] = [
            'key' => 'memory',
            'label' => 'Memory',
            'value' => $latest?->memory_used_bytes === null ? '—' : self::bytes($latest->memory_used_bytes),
            'hint' => $latest?->memory_total_bytes
                ? 'of '.self::bytes($latest->memory_total_bytes).($memoryPercent === null ? '' : ' · '.$this->percent($memoryPercent))
                : null,
            'tone' => $this->tone($memoryPercent),
        ];

        // Free space rather than used: the question anyone opens this page with is
        // whether the box is about to run out, and "12 GiB used" does not answer it
        // without knowing the disk size by heart.
        $diskPercent = $this->share($latest?->disk_used_bytes, $latest?->disk_total_bytes);
        $diskFree = $latest?->disk_total_bytes === null || $latest?->disk_used_bytes === null
            ? null
            : max(0, $latest->disk_total_bytes - $latest->disk_used_bytes);

        $cards[] = [
            'key' => 'disk',
            'label' => 'Disk free',
            'value' => $diskFree === null ? '—' : self::bytes($diskFree),
            'hint' => $latest?->disk_total_bytes
                ? 'of '.self::bytes($latest->disk_total_bytes).($diskPercent === null ? '' : ' · '.$this->percent($diskPercent).' used')
                : null,
            'tone' => $this->tone($diskPercent),
        ];

        $cards[] = [
            'key' => 'net_tx',
            'label' => 'Network out',
            'value' => $latest?->net_tx_bytes_per_sec === null ? '—' : $this->bitrate($latest->net_tx_bytes_per_sec),
            'hint' => $latest?->net_tx_bytes_per_sec === null ? null : self::bytes($latest->net_tx_bytes_per_sec).'/s to viewers',
            'tone' => 'info',
        ];

        $cards[] = [
            'key' => 'net_rx',
            'label' => 'Network in',
            'value' => $latest?->net_rx_bytes_per_sec === null ? '—' : $this->bitrate($latest->net_rx_bytes_per_sec),
            'hint' => $latest?->net_rx_bytes_per_sec === null ? null : self::bytes($latest->net_rx_bytes_per_sec).'/s pulled',
            'tone' => 'info',
        ];

        $cards[] = [
            'key' => 'uptime',
            'label' => 'Uptime',
            'value' => $latest?->uptime_seconds === null ? '—' : $this->duration($latest->uptime_seconds),
            'hint' => $latest?->recorded_at ? 'Sampled '.$latest->recorded_at->diffForHumans() : null,
            'tone' => 'info',
        ];

        return $cards;
    }

    /**
     * @param  Collection<int, ServerMetric>  $samples
     * @return array<int, array<string, mixed>>
     */
    private function charts(Server $server, Collection $samples, Carbon $since, int $minutes): array
    {
        $series = [];

        if ($server->type === ServerTypeEnum::EDGE) {
            $series[] = ['key' => 'viewer_count', 'label' => 'Viewers', 'unit' => 'count', 'tone' => 'live'];
        }

        $series = array_merge($series, [
            ['key' => 'cpu_percent', 'label' => 'CPU', 'unit' => 'percent', 'tone' => 'warn'],
            ['key' => 'memory_used_bytes', 'label' => 'Memory used', 'unit' => 'bytes', 'tone' => 'info'],
            ['key' => 'net_tx_bytes_per_sec', 'label' => 'Network out', 'unit' => 'bitrate', 'tone' => 'live'],
            ['key' => 'net_rx_bytes_per_sec', 'label' => 'Network in', 'unit' => 'bitrate', 'tone' => 'info'],
            ['key' => 'load_1', 'label' => 'Load average (1 min)', 'unit' => 'decimal', 'tone' => 'warn'],
            // Derived rather than stored: free space is total minus used, and keeping
            // one of the three in the table means they cannot disagree after a resize.
            [
                'key' => 'disk_free_bytes',
                'label' => 'Disk free',
                'unit' => 'bytes',
                'tone' => 'info',
                'value' => fn (ServerMetric $sample) => $sample->disk_total_bytes === null || $sample->disk_used_bytes === null
                    ? null
                    : max(0, $sample->disk_total_bytes - $sample->disk_used_bytes),
            ],
        ]);

        $buckets = $this->buckets($samples, $since, $minutes);

        return collect($series)
            ->map(fn (array $definition) => collect($definition)->except('value')->all() + [
                'points' => $this->points($buckets, $definition['value'] ?? $definition['key'], $minutes),
            ])
            ->filter(fn (array $chart) => collect($chart['points'])->contains(fn ($point) => $point['value'] !== null))
            ->values()
            ->all();
    }

    /**
     * Samples grouped into fixed slices of the window, so every chart shares one x axis
     * and an empty slice stays empty.
     *
     * @param  Collection<int, ServerMetric>  $samples
     * @return array<int, array{at: Carbon, samples: Collection<int, ServerMetric>}>
     */
    private function buckets(Collection $samples, Carbon $since, int $minutes): array
    {
        $size = max(1, (int) ceil($minutes / self::MAX_POINTS));
        $count = (int) ceil($minutes / $size);

        $grouped = $samples->groupBy(function (ServerMetric $sample) use ($since, $size) {
            return (int) floor($sample->recorded_at->diffInSeconds($since, absolute: true) / ($size * 60));
        });

        $buckets = [];

        for ($index = 0; $index < $count; $index++) {
            $buckets[] = [
                'at' => $since->copy()->addMinutes($index * $size),
                'samples' => $grouped->get($index, collect()),
            ];
        }

        return $buckets;
    }

    /**
     * @param  array<int, array{at: Carbon, samples: Collection<int, ServerMetric>}>  $buckets
     * @param  string|callable(ServerMetric): (int|float|null)  $key  A column, or how to derive one
     * @return array<int, array{label: string, at: string, value: float|null}>
     */
    private function points(array $buckets, string|callable $key, int $minutes): array
    {
        $read = is_string($key) ? fn (ServerMetric $sample) => $sample->{$key} : $key;

        return collect($buckets)
            ->map(function (array $bucket) use ($read, $minutes) {
                $values = $bucket['samples']
                    ->map($read)
                    ->filter(fn ($value) => $value !== null);

                return [
                    'label' => $this->axisLabel($bucket['at'], $minutes),
                    'at' => $bucket['at']->format('M j, H:i'),
                    'value' => $values->isEmpty() ? null : round($values->avg(), 2),
                ];
            })
            ->all();
    }

    private function axisLabel(Carbon $at, int $minutes): string
    {
        return $minutes > 1440 ? $at->format('D H:i') : $at->format('H:i');
    }

    private function share(?int $used, ?int $total): ?float
    {
        if ($used === null || ! $total) {
            return null;
        }

        return $used / $total * 100;
    }

    /**
     * Green until it matters, amber when it is worth watching, red when it is the
     * reason something is wrong. Unknown stays neutral rather than green.
     */
    private function tone(?float $percent): string
    {
        return match (true) {
            $percent === null => 'info',
            $percent >= 90 => 'danger',
            $percent >= 75 => 'warn',
            default => 'ok',
        };
    }

    private function percent(float $value): string
    {
        return number_format($value, $value < 10 ? 1 : 0).'%';
    }

    /**
     * Binary units, because that is what the box means by them.
     */
    public static function bytes(int|float $bytes): string
    {
        $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB', 'PiB'];
        $power = $bytes > 0 ? (int) floor(log($bytes, 1024)) : 0;
        $power = min($power, count($units) - 1);
        $value = $bytes / (1024 ** $power);

        return number_format($value, $power > 1 && $value < 100 ? 1 : 0).' '.$units[$power];
    }

    /**
     * Link speed is quoted in bits, so a byte rate reads wrong next to "1 Gbit/s
     * uplink" unless it is converted.
     */
    private function bitrate(int|float $bytesPerSecond): string
    {
        $bits = $bytesPerSecond * 8;
        $units = ['bit/s', 'kbit/s', 'Mbit/s', 'Gbit/s'];
        $power = $bits > 0 ? (int) floor(log($bits, 1000)) : 0;
        $power = min($power, count($units) - 1);
        $value = $bits / (1000 ** $power);

        return number_format($value, $power > 0 && $value < 100 ? 1 : 0).' '.$units[$power];
    }

    private function duration(int $seconds): string
    {
        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        return match (true) {
            $days > 0 => $days.'d '.$hours.'h',
            $hours > 0 => $hours.'h '.$minutes.'m',
            default => $minutes.'m',
        };
    }
}
