<?php

namespace Database\Factories;

use App\Models\AuthProvider;
use App\Models\User;
use App\Models\UserIdentity;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserIdentity>
 */
class UserIdentityFactory extends Factory
{
    protected $model = UserIdentity::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'auth_provider_id' => AuthProvider::factory(),
            'subject' => fake()->unique()->uuid(),
            'email' => fake()->unique()->safeEmail(),
            'name' => fake()->name(),
        ];
    }
}
