<?php

namespace App\Services;

use App\Models\Emote;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Laravel\Facades\Image;

class EmoteService
{
    /**
     * Emotes past this count in a single message render at the small size.
     */
    const REDUCED_SIZE_THRESHOLD = 10;

    /**
     * Stored emote dimensions.
     */
    const EMOTE_SIZE = 64;

    /**
     * Default display size in chat.
     */
    const DISPLAY_SIZE = 32;

    /**
     * Content types for the extensions the upload rules allow.
     */
    const MIME_TYPES = [
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
    ];

    /**
     * Upload and create a new emote.
     */
    public function uploadEmote(UploadedFile $file, string $name, bool $isGlobal, User $user): Emote
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $s3Key = 'emotes/'.Str::uuid().'.'.$extension;

        Storage::disk('s3')->put($s3Key, $this->resized($file), [
            'visibility' => 'private',
            'CacheControl' => 'max-age=31536000',
            'ContentType' => self::MIME_TYPES[$extension] ?? 'application/octet-stream',
        ]);

        $emote = Emote::create([
            'name' => $name,
            's3_key' => $s3Key,
            'url' => null, // resolved by the model accessor as a signed URL
            'uploaded_by_user_id' => $user->id,
            'is_global' => $isGlobal,
            'is_approved' => false,
        ]);

        $this->clearCache();

        return $emote;
    }

    /**
     * Square the upload to EMOTE_SIZE.
     */
    private function resized(UploadedFile $file): string
    {
        $image = Image::decode($file);

        // Already stored size, so the file goes up untouched and an animated gif keeps its frames.
        if ($image->width() === self::EMOTE_SIZE && $image->height() === self::EMOTE_SIZE) {
            return (string) file_get_contents($file->getRealPath());
        }

        return (string) $image->resize(self::EMOTE_SIZE, self::EMOTE_SIZE)->encode();
    }

    /**
     * Record usage for the emotes referenced in a message.
     *
     * Messages are stored as raw text: `:name:` codes are rendered client side, so
     * nothing is rewritten here.
     *
     * @return array<int, string> the emote names that resolved
     */
    public function recordUsage(string $message, User $user): array
    {
        $available = $this->getAvailableEmotes($user);

        preg_match_all('/:([a-z0-9_]+):/i', $message, $matches);

        $used = [];

        foreach (array_unique($matches[1] ?? []) as $name) {
            $name = strtolower($name);

            if (isset($available[$name])) {
                $used[] = $name;
            }
        }

        if ($used !== []) {
            $ids = array_map(fn (string $name) => $available[$name]['id'], $used);

            dispatch(function () use ($ids) {
                Emote::whereIn('id', $ids)->increment('usage_count');
            })->afterResponse();
        }

        return $used;
    }

    /**
     * Get all available emotes for a user, keyed by name.
     *
     * @return array<string, array{id: int, name: string, url: string|null, global: bool}>
     */
    public function getAvailableEmotes(User $user): array
    {
        // 6 hours stays well inside the 7 day signed URL lifetime.
        return Cache::remember($this->userCacheKey('emotes', $user), 21600, function () use ($user) {
            $indexed = [];

            foreach (Emote::availableFor($user)->get() as $emote) {
                $indexed[$emote->name] = [
                    'id' => $emote->id,
                    'name' => $emote->name,
                    'url' => $emote->url,
                    'global' => (bool) $emote->is_global,
                ];
            }

            return $indexed;
        });
    }

    /**
     * Payload shared with the frontend: a name => url map for rendering messages,
     * and a list with metadata for the picker and autocomplete.
     *
     * @return array{map: object, list: array<int, array<string, mixed>>}
     */
    public function clientPayload(User $user): array
    {
        $favoriteIds = array_flip(array_column($this->getUserFavorites($user), 'id'));

        $map = [];
        $list = [];

        foreach ($this->getAvailableEmotes($user) as $name => $emote) {
            $map[$name] = $emote['url'];
            $list[] = [
                'id' => $emote['id'],
                'name' => $emote['name'],
                'url' => $emote['url'],
                'global' => (bool) ($emote['global'] ?? false),
                'favorite' => isset($favoriteIds[$emote['id']]),
            ];
        }

        return [
            // Cast so an empty map serialises as {} rather than [].
            'map' => (object) $map,
            'list' => $list,
        ];
    }

    /**
     * Get all approved global emotes.
     */
    public function getGlobalEmotes(): array
    {
        return Cache::remember('global_emotes_'.$this->version(), 3600, function () {
            return Emote::approved()
                ->global()
                ->orderBy('usage_count', 'desc')
                ->get()
                ->map(fn (Emote $emote) => [
                    'id' => $emote->id,
                    'name' => $emote->name,
                    'url' => $emote->url,
                ])
                ->toArray();
        });
    }

    /**
     * Get user's personal emotes, including ones still awaiting approval.
     */
    public function getUserEmotes(User $user): array
    {
        return Emote::where('uploaded_by_user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(fn (Emote $emote) => [
                'id' => $emote->id,
                'name' => $emote->name,
                'url' => $emote->url,
                'is_approved' => (bool) $emote->is_approved,
                'is_global' => (bool) $emote->is_global,
            ])
            ->toArray();
    }

    /**
     * Get user's favorite emotes.
     */
    public function getUserFavorites(User $user): array
    {
        return Cache::remember($this->userCacheKey('favorites', $user), 300, function () use ($user) {
            return $user->favoriteEmotes()
                ->approved()
                ->get()
                ->map(fn (Emote $emote) => [
                    'id' => $emote->id,
                    'name' => $emote->name,
                    'url' => $emote->url,
                ])
                ->toArray();
        });
    }

    /**
     * Toggle favorite status for an emote.
     */
    public function toggleFavorite(Emote $emote, User $user): bool
    {
        if ($user->favoriteEmotes()->where('emote_id', $emote->id)->exists()) {
            $user->favoriteEmotes()->detach($emote->id);
            $isFavorited = false;
        } else {
            $user->favoriteEmotes()->attach($emote->id);
            $isFavorited = true;
        }

        Cache::forget($this->userCacheKey('favorites', $user));

        return $isFavorited;
    }

    /**
     * Validate emote name.
     */
    public function validateEmoteName(string $name): bool
    {
        return (bool) preg_match('/^[a-z0-9_]{2,20}$/', strtolower($name));
    }

    /**
     * Check if emote name is available.
     */
    public function isNameAvailable(string $name): bool
    {
        return ! Emote::where('name', strtolower($name))->exists();
    }

    /**
     * Invalidate every emote cache by bumping the version segment of their keys.
     *
     * Flushing the whole cache store would also drop rate limiters and chat settings.
     */
    public function clearCache(): void
    {
        Cache::forever('emote_cache_version', $this->version() + 1);
        Cache::forget('emote_stats');
    }

    /**
     * Get emote statistics.
     */
    public function getStatistics(): array
    {
        return Cache::remember('emote_stats', 3600, function () {
            return [
                'total_emotes' => Emote::count(),
                'approved_emotes' => Emote::approved()->count(),
                'pending_emotes' => Emote::pending()->count(),
                'global_emotes' => Emote::approved()->global()->count(),
                'total_usage' => Emote::sum('usage_count'),
                'top_emotes' => Emote::approved()
                    ->orderBy('usage_count', 'desc')
                    ->take(10)
                    ->get()
                    ->map(fn (Emote $emote) => [
                        'name' => $emote->name,
                        'url' => $emote->url,
                        'usage_count' => $emote->usage_count,
                    ])
                    ->toArray(),
            ];
        });
    }

    protected function version(): int
    {
        return (int) Cache::get('emote_cache_version', 1);
    }

    protected function userCacheKey(string $bucket, User $user): string
    {
        return "user_{$bucket}_{$user->id}_".$this->version();
    }
}
