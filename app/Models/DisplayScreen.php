<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Request;

/**
 * A screen that has presented a display key. See the create migration.
 */
class DisplayScreen extends Model
{
    /**
     * How long a screen counts as present. Both display pages poll well inside this,
     * so a screen that misses a tick or two on convention wifi is still listed, and
     * one that was unplugged drops off the list rather than being offered as a target
     * for the next hour.
     */
    public const PRESENT_MINUTES = 2;

    /**
     * Where a screen keeps the id of its own row. In its session, so a screen that is
     * handed a fresh session - a sign-out, a reimage - comes back as a new screen
     * rather than inheriting whatever the last one was told to play.
     */
    public const SESSION_KEY = 'display_screen_id';

    protected $fillable = [
        'embed_key_id',
        'label',
        'current_source_id',
        'directed_source_id',
        'directed_by',
        'directed_at',
        'page',
        'last_seen_at',
        'last_ip',
        'user_agent',
    ];

    protected $casts = [
        'directed_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];

    public function embedKey(): BelongsTo
    {
        return $this->belongsTo(EmbedKey::class);
    }

    public function currentSource(): BelongsTo
    {
        return $this->belongsTo(Source::class, 'current_source_id');
    }

    public function directedSource(): BelongsTo
    {
        return $this->belongsTo(Source::class, 'directed_source_id');
    }

    public function directedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'directed_by');
    }

    public function scopePresent(Builder $query): Builder
    {
        return $query->where('last_seen_at', '>=', now()->subMinutes(self::PRESENT_MINUTES));
    }

    /**
     * The row this session already owns, if it still exists and still belongs to the
     * key being presented.
     */
    public static function forSession(Request $request, ?EmbedKey $key = null): ?self
    {
        $id = $request->session()->get(self::SESSION_KEY);

        if (! $id) {
            return null;
        }

        return static::query()
            ->when($key, fn ($query) => $query->where('embed_key_id', $key->id))
            ->find($id);
    }

    /**
     * Record that this screen is alive, on this page, playing this source.
     *
     * Called on every poll rather than only on navigation, because a screen that has
     * been showing the same channel for two days is exactly the one an operator most
     * needs to see in the list.
     */
    public static function report(
        EmbedKey $key,
        Request $request,
        string $page,
        ?Source $current,
    ): self {
        $screen = static::forSession($request, $key) ?? new static;

        $screen->fill([
            'embed_key_id' => $key->id,
            'page' => $page,
            'last_seen_at' => now(),
            'last_ip' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 512),
        ]);

        /*
         * The hub does not play anything, so it must not clear what the screen was
         * last seen playing - somebody poking at settings should not make the row
         * read as if the wall went blank.
         */
        if ($current) {
            $screen->current_source_id = $current->id;
        }

        // Arrived where it was sent, so the instruction has been carried out and the
        // screen goes back to being switchable from the screen itself.
        if ($current && (int) $screen->directed_source_id === (int) $current->id) {
            $screen->forceFill([
                'directed_source_id' => null,
                'directed_by' => null,
                'directed_at' => null,
            ]);
        }

        $screen->save();

        $request->session()->put(self::SESSION_KEY, $screen->id);

        return $screen;
    }

    /**
     * Send this screen somewhere. A null source withdraws a standing instruction and
     * leaves the screen on whatever it is already playing.
     */
    public function directTo(?Source $source, ?User $by = null): void
    {
        $this->forceFill([
            'directed_source_id' => $source?->id,
            'directed_by' => $source ? $by?->id : null,
            'directed_at' => $source ? now() : null,
        ])->save();
    }

    public function isPresent(): bool
    {
        return $this->last_seen_at !== null
            && $this->last_seen_at->greaterThanOrEqualTo(now()->subMinutes(self::PRESENT_MINUTES));
    }

    /**
     * What to call this screen in a list. The key name is the fallback because a key
     * is usually one screen; the id suffix only matters once it is not.
     */
    public function displayName(): string
    {
        return $this->label ?: ($this->embedKey?->name ?? 'Screen').' #'.$this->id;
    }
}
