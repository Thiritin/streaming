<?php

namespace App\Support;

use App\Enum\ServerStatusEnum;
use App\Enum\ServerTypeEnum;
use App\Models\Server;
use App\Models\Source;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

/**
 * What is actually on the edge for one source, right now.
 *
 * The player on the preview page answers "can I watch it", which is the question an
 * operator usually has. This answers the one they have when the player says nothing:
 * is the ladder being written at all, which renditions exist, and how old the newest
 * segment is. It reads the edge directly with the system stream key, the same way
 * HlsController's own fetches do, so it sees what a viewer would be served rather
 * than what the database believes.
 */
final class SourceProbe
{
    /** Beyond this the newest segment is old enough that nothing is arriving. */
    private const STALE_AFTER_SECONDS = 20;

    private const TIMEOUT = 3;

    /**
     * @return array<string, mixed>
     */
    public static function run(Source $source): array
    {
        // Local dev loops are static files under public/, with no edge in front of
        // them and nothing to ask.
        if (config('stream.dev_streams')) {
            return self::result(null, null, 'Local dev streams are served from public/dev-streams; there is no edge to probe.');
        }

        $edge = Server::where('status', ServerStatusEnum::ACTIVE)
            ->where('type', ServerTypeEnum::EDGE)
            ->orderBy('viewer_count')
            ->first();

        if (! $edge || ! $edge->hostname) {
            return self::result(false, null, 'No active edge server to ask.');
        }

        $base = self::base($edge->hostname, $edge->port ?? 8080);
        $master = self::get("{$base}/live/{$source->slug}_master.m3u8");

        if ($master === null) {
            return self::result(false, $edge->hostname, 'The edge did not answer.');
        }

        if ($master['status'] === 404) {
            return self::result(false, $edge->hostname, 'Nothing is being published under this stream name.');
        }

        if ($master['status'] !== 200) {
            return self::result(false, $edge->hostname, "The edge answered {$master['status']}.");
        }

        $renditions = self::renditions($master['body'], $base);

        if ($renditions === []) {
            return self::result(false, $edge->hostname, 'The master playlist is there but lists no renditions.');
        }

        $freshest = collect($renditions)
            ->pluck('age_seconds')
            ->filter(fn ($age) => $age !== null)
            ->min();

        return self::result(
            $freshest !== null && $freshest <= self::STALE_AFTER_SECONDS,
            $edge->hostname,
            match (true) {
                $freshest === null => 'Segments are listed but carry no timestamps.',
                $freshest <= self::STALE_AFTER_SECONDS => 'Segments are arriving.',
                default => 'The newest segment is '.self::duration($freshest).' old; the encoder has probably stopped.',
            },
            $renditions,
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $renditions
     * @return array<string, mixed>
     */
    private static function result(?bool $ok, ?string $edge, string $message, array $renditions = []): array
    {
        return [
            'ok' => $ok,
            'edge' => $edge,
            'message' => $message,
            'renditions' => $renditions,
            'checked_at' => now()->format('H:i:s'),
        ];
    }

    /**
     * One entry per rendition the master lists, each with the state of its own playlist.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function renditions(string $master, string $base): array
    {
        $lines = preg_split('/\r?\n/', $master) ?: [];
        $renditions = [];
        $attributes = null;

        foreach ($lines as $line) {
            $line = trim($line);

            if (str_starts_with($line, '#EXT-X-STREAM-INF:')) {
                $attributes = substr($line, strlen('#EXT-X-STREAM-INF:'));

                continue;
            }

            if ($line === '' || str_starts_with($line, '#') || $attributes === null) {
                continue;
            }

            $name = preg_match('/^(.+)_(sd|hd|fhd)\.m3u8$/', $line, $matches) ? $matches[2] : $line;
            $variant = self::get("{$base}/live/{$line}");

            $renditions[] = [
                'name' => strtoupper($name),
                'bandwidth' => self::attribute($attributes, 'BANDWIDTH'),
                'resolution' => self::attribute($attributes, 'RESOLUTION'),
                'codecs' => self::attribute($attributes, 'CODECS'),
            ] + self::playlistState($variant);

            $attributes = null;
        }

        return $renditions;
    }

    /**
     * @param  array{status: int, body: string}|null  $variant
     * @return array<string, mixed>
     */
    private static function playlistState(?array $variant): array
    {
        if ($variant === null || $variant['status'] !== 200) {
            return [
                'available' => false,
                'segments' => 0,
                'window_seconds' => null,
                'age_seconds' => null,
                'age' => $variant === null ? 'unreachable' : (string) $variant['status'],
            ];
        }

        $lines = preg_split('/\r?\n/', $variant['body']) ?: [];
        $segments = 0;
        $window = 0.0;
        /*
         * The ladder is written with program_date_time, so the newest segment's wall
         * clock is the last stamp plus whatever ran after it. That is what makes the
         * age real rather than a guess from the segment count.
         */
        $stamp = null;
        $sinceStamp = 0.0;
        $pending = 0.0;

        foreach ($lines as $line) {
            $line = trim($line);

            if (str_starts_with($line, '#EXT-X-PROGRAM-DATE-TIME:')) {
                $value = substr($line, strlen('#EXT-X-PROGRAM-DATE-TIME:'));
                $parsed = rescue(fn () => CarbonImmutable::parse($value), null, false);

                if ($parsed) {
                    $stamp = $parsed;
                    $sinceStamp = 0.0;
                }

                continue;
            }

            if (str_starts_with($line, '#EXTINF:')) {
                $pending = (float) trim(substr($line, strlen('#EXTINF:')), ", \t");

                continue;
            }

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $segments++;
            $window += $pending;
            $sinceStamp += $pending;
            $pending = 0.0;
        }

        $newest = $stamp?->addSeconds((int) round($sinceStamp));

        return [
            'available' => $segments > 0,
            'segments' => $segments,
            'window_seconds' => (int) round($window),
            'age_seconds' => $newest ? max(0, now()->diffInSeconds($newest, true)) : null,
            'age' => $newest ? self::duration((int) now()->diffInSeconds($newest, true)).' ago' : 'unknown',
        ];
    }

    private static function attribute(string $attributes, string $key): ?string
    {
        return preg_match('/(?:^|,)'.$key.'=("[^"]*"|[^,]*)/', $attributes, $matches)
            ? trim($matches[1], '"')
            : null;
    }

    /**
     * @return array{status: int, body: string}|null
     */
    private static function get(string $url): ?array
    {
        $client = Http::timeout(self::TIMEOUT)->withHeaders(self::headers());

        if (str_starts_with($url, 'https://')) {
            // Edges terminate TLS themselves, as in HlsController::servePlaylist.
            $client = $client->withOptions(['verify' => false]);
        }

        return rescue(function () use ($client, $url) {
            $response = $client->get($url);

            return ['status' => $response->status(), 'body' => $response->body()];
        }, null, false);
    }

    /**
     * @return array<string, string>
     */
    private static function headers(): array
    {
        $key = config('stream.system_streamkey');

        return $key ? ['X-Stream-Key' => $key] : [];
    }

    private static function base(string $hostname, int $port): string
    {
        return $port === 443 ? "https://{$hostname}" : "http://{$hostname}:{$port}";
    }

    private static function duration(int $seconds): string
    {
        return $seconds < 90 ? $seconds.'s' : intdiv($seconds, 60).'m';
    }
}
