<?php

namespace Database\Factories;

use App\Models\CareRelationship;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CareRelationship>
 */
class CareRelationshipFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'doctor_id' => User::factory()->doctor(),
            'patient_id' => User::factory()->patient(),
            'started_at' => fake()->dateTimeBetween('-1 year', '-1 day'),
        ];
    }

    /**
     * Завершённая связь: врач больше не ведёт пациента.
     */
    public function ended(): static
    {
        return $this->state(fn (array $attributes) => [
            'ended_at' => now(),
        ]);
    }
}
