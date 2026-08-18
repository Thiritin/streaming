<?php

namespace App\Services;

use App\Events\ShowBooped;
use App\Models\Show;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * The boop counter, kept in the cache and written back on a tick.
 *
 * A boop is one click of a button people are invited to mash, so the write path
 * has to survive a rate no other feature here produces. Nothing touches the
 * database or the websocket on the request itself: clicks land on a cache
 * counter, and FlushShowBoopsJob turns whatever piled up into one UPDATE and one
 * broadcast per show every few seconds. A room mashing 2000 boops a second costs
 * the same as a room mashing two.
 *
 * The counter in the cache is the live number and `shows.boop_count` is its
 * durable copy, so a lost cache costs at most one tick's worth of boops.
 */
class BoopCounter
{
    private const TTL_HOURS = 24;

    private const DIRTY_KEY = 'boops:dirty';

    /**
     * Count a batch of boops and return the room's running total.
     */
    public function add(Show $show, int $count): int
    {
        $total = $this->bump($this->totalKey($show->id), $count, (int) $show->boop_count);

        $this->bump($this->pendingKey($show->id), $count, 0);
        $this->markDirty($show->id);

        return $total;
    }

    /**
     * The live total, which is ahead of the column by up to one tick.
     */
    public function total(Show $show): int
    {
        return max(
            (int) Cache::get($this->totalKey($show->id), 0),
            (int) $show->boop_count,
        );
    }

    /**
     * Bank what has piled up since the last tick: one UPDATE and one broadcast
     * per show that saw any boops at all.
     */
    public function flush(): void
    {
        $stillDirty = [];

        foreach ($this->dirtyShowIds() as $showId) {
            $delta = (int) Cache::pull($this->pendingKey($showId), 0);

            if ($delta <= 0) {
                continue;
            }

            // Boops arriving during the flush land on the cache total and on a
            // fresh pending counter, so they are counted on the next tick.
            $stillDirty[] = $showId;

            Show::whereKey($showId)->increment('boop_count', $delta);

            $total = max(
                (int) Show::whereKey($showId)->value('boop_count'),
                (int) Cache::get($this->totalKey($showId), 0),
            );

            try {
                broadcast(new ShowBooped($showId, $total, $delta));
            } catch (\Throwable $e) {
                // The boops are banked either way; a websocket server that is down
                // costs the room its animation, not its count.
                Log::warning('Boop broadcast failed', ['show_id' => $showId, 'error' => $e->getMessage()]);
            }
        }

        Cache::put(self::DIRTY_KEY, $stillDirty, now()->addHours(self::TTL_HOURS));
    }

    /**
     * @return array<int, int>
     */
    private function dirtyShowIds(): array
    {
        $tracked = (array) Cache::get(self::DIRTY_KEY, []);

        // Live shows are checked regardless: the tracked list is written without a
        // lock, so a lost id must not mean a show that stops counting.
        $live = Show::where('status', 'live')->pluck('id')->all();

        return array_values(array_unique(array_map('intval', array_merge($tracked, $live))));
    }

    private function markDirty(int $showId): void
    {
        $ids = (array) Cache::get(self::DIRTY_KEY, []);

        if (in_array($showId, $ids, true)) {
            return;
        }

        $ids[] = $showId;

        Cache::put(self::DIRTY_KEY, $ids, now()->addHours(self::TTL_HOURS));
    }

    /**
     * Increment a counter that may not exist yet, starting it from `$base`.
     * Both the file and the redis store start a missing key at zero, which would
     * quietly drop the total back to this session's clicks.
     */
    private function bump(string $key, int $by, int $base): int
    {
        if (Cache::add($key, $base + $by, now()->addHours(self::TTL_HOURS))) {
            return $base + $by;
        }

        return (int) Cache::increment($key, $by);
    }

    private function totalKey(int $showId): string
    {
        return "show:{$showId}:boops:total";
    }

    private function pendingKey(int $showId): string
    {
        return "show:{$showId}:boops:pending";
    }
}
