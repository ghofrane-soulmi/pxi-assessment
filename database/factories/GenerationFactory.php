<?php

namespace Database\Factories;

use App\Models\Generation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Generation>
 */
class GenerationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'prompt' => $this->faker->sentence(),
            'size' => '1024x1024',
            'quality' => 'low',
            'reference_images' => [],
            'min_charge_nanos' => 14_050_000,
            'max_charge_nanos' => 21_887_500,
        ];
    }
}
