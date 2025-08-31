<?php

namespace Database\Factories;

use App\Enum\SourceStatusEnum;
use App\Models\Source;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Source>
 */
class SourceFactory extends Factory
{
    protected $model = Source::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = $this->faker->unique()->words(3, true);
        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'stream_key' => Str::random(32),
            'status' => SourceStatusEnum::OFFLINE,
            'description' => $this->faker->optional()->sentence(),
        ];
    }

    /**
     * Indicate that the source is online.
     */
    public function online(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SourceStatusEnum::ONLINE,
        ]);
    }

    /**
     * Indicate that the source is offline.
     */
    public function offline(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SourceStatusEnum::OFFLINE,
        ]);
    }

    /**
     * Indicate that the source has an error.
     */
    public function error(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SourceStatusEnum::ERROR,
        ]);
    }
}