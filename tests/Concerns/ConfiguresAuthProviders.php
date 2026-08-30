<?php

namespace Tests\Concerns;

use App\Models\AuthProvider;
use App\Models\User;
use App\Models\UserIdentity;

/**
 * The provider rows a test needs to have a way in.
 *
 * The convention's own row is seeded by the migration, so it is fetched rather than
 * made: `key` and `redirect_path` are both unique and a second one would collide.
 */
trait ConfiguresAuthProviders
{
    protected function legacyProvider(array $overrides = []): AuthProvider
    {
        $provider = AuthProvider::legacy() ?? AuthProvider::factory()->legacy()->create();

        $provider->update(array_merge([
            'client_id' => 'streaming',
            'client_secret' => 'a-client-secret',
            'issuer_url' => 'https://identity.example.org',
            'enabled' => true,
        ], $overrides));

        return $provider->refresh();
    }

    protected function connect(User $user, AuthProvider $provider, ?string $subject = null): UserIdentity
    {
        return UserIdentity::create([
            'user_id' => $user->id,
            'auth_provider_id' => $provider->id,
            'subject' => $subject ?? $user->sub ?? 'subject-'.$user->id.'-'.$provider->id,
            'email' => $user->email,
            'name' => $user->name,
        ]);
    }
}
