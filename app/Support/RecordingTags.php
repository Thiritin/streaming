<?php

namespace App\Support;

use App\Models\Show;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

/**
 * The free-text side of the recording plan: whatever else a room tracks about a show.
 *
 * Every convention runs its recordings slightly differently - one deposits to a NAS, one
 * hands masters to an editor, one has a colour pass - and none of that is worth a column,
 * because the column is wrong again next year. So there is no vocabulary here: a tag is
 * whatever somebody typed, and the set already in use is offered back as suggestions,
 * which is what keeps thirty people's typing to one vocabulary without anyone having to
 * define it first.
 *
 * Tags are stored lower-cased for exactly that reason. "NAS", "Nas" and "nas" are the
 * same errand, and a filter that treats them as three is a filter that quietly loses
 * rows - `whereJsonContains` matches the stored string, so the folding has to happen on
 * the way in rather than at read time.
 */
final class RecordingTags
{
    private const CACHE_KEY = 'manage.recording-tags';

    private const CACHE_TTL = 60;

    /**
     * One tag, as it is stored: lower-cased, trimmed, inner whitespace collapsed so
     * "saved to  nas" and "saved to nas" are not two errands. Null when there is nothing
     * left, which is how an empty box says "no tag".
     */
    public static function normalise(?string $tag): ?string
    {
        $tag = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', (string) $tag)));

        return $tag === '' ? null : mb_substr($tag, 0, Show::MAX_TAG_LENGTH);
    }

    /**
     * A whole list on its way into the column: normalised, de-duplicated and capped.
     *
     * @param  iterable<mixed>  $tags
     * @return array<int, string>
     */
    public static function clean(iterable $tags): array
    {
        $clean = [];

        foreach ($tags as $tag) {
            $tag = self::normalise(is_string($tag) ? $tag : null);

            if ($tag !== null && ! in_array($tag, $clean, true)) {
                $clean[] = $tag;
            }
        }

        return array_slice($clean, 0, Show::MAX_TAGS);
    }

    /**
     * Every tag in use on this installation, sorted, for the suggestion list and the
     * filter. Cached briefly: it is read on every load of a page whose rows change far
     * more often than its vocabulary does.
     *
     * @return array<int, string>
     */
    public static function inUse(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
            $tags = Show::whereNotNull('recording_tags')
                ->pluck('recording_tags')
                ->flatten()
                ->filter(fn ($tag) => is_string($tag) && $tag !== '')
                ->unique()
                ->sort()
                ->values()
                ->all();

            return array_map('strval', $tags);
        });
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Narrow a show query to the rows carrying one tag.
     *
     * @param  Builder<Show>  $query
     * @return Builder<Show>
     */
    public static function scope(Builder $query, string $tag): Builder
    {
        $tag = self::normalise($tag);

        return $tag === null ? $query : $query->whereJsonContains('recording_tags', $tag);
    }
}
