<?php

namespace Database\Factories;

use App\Models\Drug;
use App\Models\DrugStrength;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DrugStrength>
 */
class DrugStrengthFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'drug_id' => Drug::factory(),
            'mg' => 20,
            'divisible' => true,
            'is_active' => true,
        ];
    }

    /**
     * Дозировка убрана из файла справочника.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
