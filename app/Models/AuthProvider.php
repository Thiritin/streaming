<?php

namespace App\Models;

use App\Services\Auth\ProviderFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One way in that is not a password: the convention's own identity provider, or
 * anything else an installation wires up.
 *
 * A row, not a settings key. The settings registry declares a static field list, so N
 * providers with M fields each cannot live in it, and a delete would have to sweep an
 * unknown key prefix. Events and categories set the precedent: a settings area whose
 * contents are rows.
 *
 * The secret is encrypted by an Eloquent cast rather than by BrandingSetting's own
 * mechanism. Same APP_KEY, so a key rotation covers both; the difference is that a
 * provider secret never reaches the config repository and so is never at risk from
 * config:cache.
 */
class AuthProvider extends Model
{
    use HasFactory;

    /**
     * The callback URI the convention's identity provider already has registered.
     * Kept rather than moved onto the generated three-segment one, because changing
     * it is a change on somebody else's system, on their schedule.
     */
    public const LEGACY_REDIRECT_PATH = '/auth/callback';

    protected $fillable = [
        'driver',
        'key',
        'label',
        'client_id',
        'client_secret',
        'scopes',
        'issuer_url',
        'endpoints',
        'redirect_path',
        'enabled',
        'order',
        'grants_baseline',
        'role_map',
        'packages_url',
    ];

    protected $hidden = [
        'client_secret',
    ];

    protected $casts = [
        'client_secret' => 'encrypted',
        'scopes' => 'array',
        'endpoints' => 'array',
        'role_map' => 'array',
        'enabled' => 'boolean',
        'grants_baseline' => 'boolean',
        'order' => 'integer',
    ];

    public function identities(): HasMany
    {
        return $this->hasMany(UserIdentity::class);
    }

    /**
     * Where the provider sends people back to.
     */
    public function redirectUrl(): string
    {
        return $this->redirect_path
            ? url($this->redirect_path)
            : route('auth.provider.callback', $this->key);
    }

    public function redirectStartUrl(): string
    {
        return route('auth.provider.redirect', $this->key);
    }

    /**
     * Whether there is something to talk to. A switch with no endpoint behind it is a
     * button that fails on the second page, so it is never offered.
     */
    public function isConfigured(): bool
    {
        if (blank($this->client_id) || blank($this->client_secret)) {
            return false;
        }

        if ($this->driver !== 'oidc') {
            return true;
        }

        return filled($this->issuer_url) || filled($this->endpoints['authorization_endpoint'] ?? null);
    }

    public function isUsable(): bool
    {
        return $this->enabled && $this->isConfigured() && ProviderFactory::supports($this->driver);
    }

    /**
     * @param  Builder<AuthProvider>  $query
     */
    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('enabled', true);
    }

    /**
     * @param  Builder<AuthProvider>  $query
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('order')->orderBy('id');
    }

    /**
     * Every provider that is switched on and has an endpoint behind it, in button order.
     *
     * @return Collection<int, AuthProvider>
     */
    public static function usable(): Collection
    {
        return static::query()->enabled()->ordered()->get()
            ->filter(fn (AuthProvider $provider) => $provider->isUsable())
            ->values();
    }

    /**
     * The row that owns /auth/callback, which is where every link already in the wild
     * and every registration at the provider still points.
     */
    public static function legacy(): ?AuthProvider
    {
        return static::query()->where('redirect_path', self::LEGACY_REDIRECT_PATH)->first();
    }
}
