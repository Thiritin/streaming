<?php

namespace App\Support;

use App\Support\Manage\Overview;
use App\Support\Manage\Status;
use Illuminate\Support\Facades\Cache;

/**
 * The dashboard's alert list turned into a stream of changes.
 *
 * The list itself is a snapshot: it answers "what is wrong now", recomputed on every
 * poll. A chat wants the other question - what became wrong since the last time anyone
 * looked - so this keeps the set of conditions it has already announced and reports only
 * the difference. An alert standing for six hours is one message, not three hundred and
 * sixty.
 *
 * Two rules on top of the plain diff. A condition has to be seen HOLD_TICKS times in a
 * row before it is posted, because a single failed health check or one late heartbeat is
 * not an outage and a chat that cries about every flap stops being read. And a condition
 * that escalates from a warning to a danger is posted again, because a disk at 9% free
 * and a disk at 4% free are not the same news.
 */
final class HealthAlertDigest
{
    public const CACHE_KEY = 'telegram_health_alerts';

    /**
     * Consecutive ticks a condition must survive before it is worth a message. The job
     * runs every minute, so this is "still wrong a minute later".
     */
    public const HOLD_TICKS = 2;

    public function __construct(private readonly Overview $overview) {}

    /**
     * Advance the state by one tick and answer what changed.
     *
     * @return array{raised: array<int, array<string, mixed>>, cleared: array<int, array<string, mixed>>}
     */
    public function tick(): array
    {
        $current = [];

        foreach ($this->overview->alerts() as $alert) {
            $current[$alert['key']] = $alert;
        }

        $previous = Cache::get(self::CACHE_KEY, []);
        $next = [];
        $raised = [];
        $cleared = [];

        foreach ($current as $key => $alert) {
            $was = is_array($previous[$key] ?? null) ? $previous[$key] : [];
            $seen = (int) ($was['seen'] ?? 0) + 1;
            $posted = (bool) ($was['posted'] ?? false);

            $escalated = $posted
                && ($was['tone'] ?? null) !== Status::DANGER
                && $alert['tone'] === Status::DANGER;

            if ($escalated || (! $posted && $seen >= self::HOLD_TICKS)) {
                $raised[] = $alert;
                $posted = true;
            }

            $next[$key] = [
                'tone' => $alert['tone'],
                'title' => $alert['title'],
                'sourceId' => $alert['sourceId'] ?? null,
                'seen' => $seen,
                'posted' => $posted,
            ];
        }

        foreach ($previous as $key => $was) {
            if (isset($current[$key]) || ! is_array($was) || ! ($was['posted'] ?? false)) {
                continue;
            }

            $cleared[] = [
                'key' => $key,
                'title' => $was['title'] ?? $key,
                'tone' => $was['tone'] ?? Status::WARN,
                'sourceId' => $was['sourceId'] ?? null,
            ];
        }

        Cache::put(self::CACHE_KEY, $next, now()->addDay());

        return ['raised' => $raised, 'cleared' => $cleared];
    }

    /**
     * Drop what has been announced, so the next tick starts from the state the
     * installation is actually in. What a chat that has just been switched on wants is
     * everything currently wrong, not silence until the next thing breaks.
     */
    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
