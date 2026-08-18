<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * A credential for a screen rather than a person. See the create migration.
 */
class EmbedKey extends Model
{
    use HasFactory;

    /**
     * Crockford base32. I, L, O and U are absent, so there is no 0/O or 1/I/l
     * ambiguity for someone reading a code off a sheet and typing it into a TV
     * remote's on-screen keyboard.
     */
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    /**
     * 8 characters is 40 bits. Short enough to type, and unguessable in practice
     * because entry is rate limited and the stored hash is peppered - the two
     * things that make a short secret safe. Do not lower this without both.
     */
    private const LENGTH = 8;

    protected $fillable = [
        'name',
        'key',
        'key_hash',
        'last_used_at',
        'last_used_ip',
    ];

    protected $casts = [
        'key' => 'encrypted',
        'last_used_at' => 'datetime',
        'signed_out_at' => 'datetime',
    ];

    protected $hidden = [
        'key',
        'key_hash',
    ];

    /**
     * The playlist path caches the row (HlsController::embedKeyAuthorises), so a
     * revoked or signed-out key has to leave that cache as it is written rather than
     * when the entry happens to lapse.
     */
    protected static function booted(): void
    {
        $forget = fn (self $key) => Cache::forget('hls_embed_key:'.$key->getKey());

        static::saved($forget);
        static::deleted($forget);
    }

    /**
     * The screens currently holding a session minted from this code.
     */
    public function screens(): HasMany
    {
        return $this->hasMany(DisplayScreen::class);
    }

    /**
     * Mint a key. The plaintext is only ever readable through the model's own
     * decryption, so the caller does not have to carry it back separately.
     */
    public static function generate(string $name): self
    {
        $key = static::mint();

        return static::create([
            'name' => $name,
            'key' => $key,
            'key_hash' => static::hash($key),
        ]);
    }

    /**
     * Resolve a presented key. Constant work regardless of how many keys exist,
     * which an encrypted column could not offer.
     */
    public static function findByKey(?string $key): ?self
    {
        if ($key === null || $key === '') {
            return null;
        }

        return static::where('key_hash', static::hash($key))->first();
    }

    /**
     * Peppered with the app key rather than a bare sha256.
     *
     * A 40-bit secret hashed unkeyed would fall to an offline sweep in minutes if
     * the table ever leaked. HMAC means the database alone is not enough, so the
     * short code only has to survive online guessing, which the route throttle
     * already stops. Rotating APP_KEY invalidates every key, the same way it
     * already invalidates the encrypted column beside it.
     */
    public static function hash(string $key): string
    {
        return hash_hmac('sha256', static::normalize($key), (string) config('app.key'));
    }

    /**
     * Fold the ways a human types a code back onto the one canonical form.
     *
     * Lowercase, missing or extra dashes, spaces from a barcode scanner, and the
     * letters the alphabet dropped (O for zero, I or L for one, U for V) all land
     * on the same string, so a typo that is not really a typo still works.
     */
    public static function normalize(string $key): string
    {
        $upper = strtoupper(trim($key));
        $folded = strtr($upper, ['O' => '0', 'I' => '1', 'L' => '1', 'U' => 'V']);
        $bare = preg_replace('/[^0-9A-Z]/', '', $folded) ?? '';

        return strlen($bare) === self::LENGTH ? static::group($bare) : $bare;
    }

    /**
     * A code no existing row already holds. The keyspace makes a clash a lottery
     * win, but the hash column is unique, so losing that lottery would be a 500.
     */
    public static function mint(): string
    {
        do {
            $bare = '';

            for ($i = 0; $i < self::LENGTH; $i++) {
                $bare .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }

            $key = static::group($bare);
        } while (static::where('key_hash', static::hash($key))->exists());

        return $key;
    }

    private static function group(string $bare): string
    {
        return implode('-', str_split($bare, 4));
    }

    /**
     * Last-seen bookkeeping, so an operator can tell a live screen from a key that
     * was issued and never used. Deliberately not written on every request: the
     * display polls, and this is a diagnostic rather than a counter.
     */
    public function touchUsage(Request $request): void
    {
        if ($this->last_used_at?->diffInMinutes(now()) < 5) {
            return;
        }

        $this->forceFill([
            'last_used_at' => now(),
            'last_used_ip' => $request->ip(),
        ])->save();
    }

    /**
     * Sign out every screen currently using this code, without revoking it.
     *
     * Revoking is the bigger hammer and means reissuing a code to the screens that
     * were fine. This is for the one screen that walked off: the code still works,
     * but every session already minted from it stops resolving.
     */
    public function signOutScreens(): void
    {
        $this->forceFill(['signed_out_at' => now()])->save();

        // The rows describe live screens. Those sessions stop resolving as of now, so
        // leaving them listed would offer /manage a screen it can no longer reach.
        $this->screens()->delete();
    }

    /**
     * Whether a session minted at this time is still one this key vouches for.
     */
    public function acceptsSessionFrom(?int $timestamp): bool
    {
        if (! $this->signed_out_at) {
            return true;
        }

        return $timestamp !== null && $timestamp >= $this->signed_out_at->getTimestamp();
    }

    public function displayUrl(): string
    {
        return route('display.enter', ['key' => $this->key]);
    }

    /**
     * What the URL looks like when it has to be read aloud or typed: the host and
     * the code, nothing else.
     */
    public function typableUrl(): string
    {
        return preg_replace('#^https?://#', '', $this->displayUrl()) ?? $this->displayUrl();
    }
}
