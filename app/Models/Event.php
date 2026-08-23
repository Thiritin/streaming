<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * One run of the convention - "Eurofurence 30" - and the days it covers.
 *
 * Two things read the dates and nothing else does:
 *
 *  1. Whether the site is in its live state. Inside a window the front page is a
 *     programme: what is on, what is next. Outside one there is nothing to be next,
 *     so the front page is the archive.
 *  2. What a show or a recording belongs to. That is what the archive files by,
 *     in place of the calendar year it used to guess from the date.
 *
 * It gates nothing. A show still goes live because somebody put it live, and a
 * channel is still watchable only while a show on it is live - see the show gate.
 * An event window being closed never stops a stream, it only stops the front page
 * pretending there is a programme.
 */
class Event extends Model
{
    use HasFactory;

    /**
     * How long the resolved window is held. Short: the answer changes on its own at
     * midnight on two days of the year, and the cache exists so the front page does
     * not ask on every request, not to avoid asking at all.
     */
    private const CACHE_TTL = 60;

    private const CACHE_KEY = 'events.window';

    protected $fillable = [
        'name',
        'slug',
        'starts_on',
        'ends_on',
    ];

    protected $casts = [
        'starts_on' => 'date',
        'ends_on' => 'date',
    ];

    protected static function boot()
    {
        parent::boot();

        static::saving(function (Event $event) {
            if (empty($event->slug)) {
                $event->slug = Str::slug($event->name);
            }
        });

        // Any write can move a window, and the window is what the front page renders
        // its whole shape from, so it is dropped on every one rather than on the dates.
        static::saved(fn () => self::forgetWindow());
        static::deleted(fn () => self::forgetWindow());
    }

    public function shows()
    {
        return $this->hasMany(Show::class);
    }

    public function recordings()
    {
        return $this->hasMany(Recording::class);
    }

    public static function forgetWindow(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * The first day, from its first moment.
     */
    public function startsAt(): Carbon
    {
        return $this->starts_on->copy()->startOfDay();
    }

    /**
     * The last day, to its last moment. The window is inclusive of the closing day:
     * an event billed as running "to Sunday" is not over on Sunday morning.
     */
    public function endsAt(): Carbon
    {
        return $this->ends_on->copy()->endOfDay();
    }

    public function covers(CarbonInterface $moment): bool
    {
        return $moment->betweenIncluded($this->startsAt(), $this->endsAt());
    }

    public function hasEnded(): bool
    {
        return $this->endsAt()->isPast();
    }

    /**
     * How the event is described wherever a date range is shown. Collapses the parts
     * the two ends share, so a run inside one month reads "12 - 16 August 2026"
     * rather than saying August 2026 twice.
     */
    public function dateRange(): string
    {
        $start = $this->starts_on;
        $end = $this->ends_on;

        if ($start->isSameDay($end)) {
            return $start->format('j F Y');
        }

        if ($start->year !== $end->year) {
            return $start->format('j F Y').' - '.$end->format('j F Y');
        }

        if ($start->month !== $end->month) {
            return $start->format('j F').' - '.$end->format('j F Y');
        }

        return $start->format('j').' - '.$end->format('j F Y');
    }

    /**
     * Newest run first, which is the order every list of them wants.
     */
    public function scopeOrdered($query)
    {
        return $query->orderByDesc('starts_on');
    }

    /**
     * The event happening right now, or null between runs.
     *
     * Cached with the neighbouring answers in one entry, because the front page wants
     * all three and they are one table read.
     */
    public static function current(): ?self
    {
        return self::window()['current'];
    }

    /**
     * The next run that has not started, or null when nothing further is scheduled.
     */
    public static function next(): ?self
    {
        return self::window()['next'];
    }

    /**
     * The most recent run that has finished. This is the one the archive leads with
     * once an event is over: the recordings people are looking for are its.
     */
    public static function latestFinished(): ?self
    {
        return self::window()['previous'];
    }

    /**
     * Whether anybody has set the calendar up at all.
     *
     * Everything that changes behaviour is gated on this, so an installation with no
     * events keeps the shape it had before they existed rather than reading as one
     * long gap between runs.
     */
    public static function configured(): bool
    {
        return self::window()['configured'];
    }

    /**
     * Whether the site is in its live state: a run is on.
     */
    public static function isLive(): bool
    {
        return self::current() !== null;
    }

    /**
     * The event a moment falls inside. What a show and a recording are filed by
     * when nobody has said otherwise.
     */
    public static function forDate(?CarbonInterface $moment): ?self
    {
        if ($moment === null) {
            return null;
        }

        return self::query()
            ->whereDate('starts_on', '<=', $moment->toDateString())
            ->whereDate('ends_on', '>=', $moment->toDateString())
            ->orderByDesc('starts_on')
            ->first();
    }

    /**
     * Current, next and previous in one read.
     *
     * @return array{current: ?self, next: ?self, previous: ?self, configured: bool}
     */
    private static function window(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
            $today = now()->toDateString();

            $current = self::query()
                ->whereDate('starts_on', '<=', $today)
                ->whereDate('ends_on', '>=', $today)
                ->orderByDesc('starts_on')
                ->first();

            $next = self::query()
                ->whereDate('starts_on', '>', $today)
                ->orderBy('starts_on')
                ->first();

            $previous = self::query()
                ->whereDate('ends_on', '<', $today)
                ->orderByDesc('ends_on')
                ->first();

            return [
                'current' => $current,
                'next' => $next,
                'previous' => $previous,
                'configured' => $current || $next || $previous || self::query()->exists(),
            ];
        });
    }
}
