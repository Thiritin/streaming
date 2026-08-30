<?php

namespace Database\Factories;

use App\Models\AuthProvider;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuthProvider>
 */
class AuthProviderFactory extends Factory
{
    protected $model = AuthProvider::class;

    public function definition(): array
    {
        $key = fake()->unique()->slug(1);

        return [
            'driver' => 'oidc',
            'key' => $key,
            'label' => ucfirst($key),
            'client_id' => 'streaming',
            'client_secret' => 'a-client-secret',
            'issuer_url' => 'https://'.$key.'.example.org',
            'enabled' => true,
            'grants_baseline' => true,
        ];
    }

    public function disabled(): static
    {
        return $this->state(fn () => ['enabled' => false]);
    }

    /**
     * The row that owns /auth/callback: what every existing installation has.
     */
    public function legacy(): static
    {
        return $this->state(fn () => [
            'key' => 'identity',
            'label' => 'Identity provider',
            'redirect_path' => AuthProvider::LEGACY_REDIRECT_PATH,
        ]);
    }
}
