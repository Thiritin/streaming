<?php

namespace Database\Factories;

use App\Models\Show;
use App\Models\Source;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Show>
 */
class ShowFactory extends Factory
{
    protected $model = Show::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => $this->faker->sentence(3),
            'slug' => Str::slug($this->faker->unique()->sentence(3)),
            'description' => $this->faker->paragraph(),
            'source_id' => Source::factory(),
            'status' => 'scheduled',
            'scheduled_start' => now()->addHour(),
            'scheduled_end' => now()->addHours(3),
            'actual_start' => null,
            'actual_end' => null,
            'viewer_count' => 0,
            'priority' => 50,
        ];
    }

    /**
     * Indicate that the show is live.
     */
    public function live(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'live',
            'actual_start' => now(),
            'viewer_count' => $this->faker->numberBetween(0, 1000),
        ]);
    }

    /**
     * Indicate that the show is scheduled.
     */
    public function scheduled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'scheduled',
        ]);
    }

    /**
     * Indicate that the show has ended.
     */
    public function ended(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'ended',
            'actual_end' => now(),
        ]);
    }
}
