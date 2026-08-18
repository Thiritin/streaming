<?php

namespace Database\Factories;

use App\Models\EmbedKey;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmbedKey>
 */
class EmbedKeyFactory extends Factory
{
    protected $model = EmbedKey::class;

    public function definition(): array
    {
        $key = EmbedKey::mint();

        return [
            'name' => $this->faker->words(2, true).' screen',
            'key' => $key,
            'key_hash' => EmbedKey::hash($key),
        ];
    }
}
