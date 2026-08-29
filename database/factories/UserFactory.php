<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sub' => fake()->unique()->uuid(),
            'name' => fake()->name(),
            'reg_id' => fake()->optional()->numberBetween(1000, 9999),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * An account this installation holds itself: no subject, and a password to sign
     * in with. The default state stays an identity-provider account, because every
     * existing test is written against that shape.
     */
    public function local(string $password = 'password'): static
    {
        return $this->state(fn (array $attributes) => [
            'sub' => null,
            'email' => fake()->unique()->safeEmail(),
            'password' => $password,
            'email_verified_at' => now(),
        ]);
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
