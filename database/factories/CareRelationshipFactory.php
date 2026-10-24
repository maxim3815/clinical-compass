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
            // От часов приложения, а не Faker: тест с travelTo в прошлое иначе получает связь,
            // которая начинается позже, чем её завершают в тесте.
            'started_at' => now()->subDays(fake()->numberBetween(1, 365))->subMinutes(fake()->numberBetween(0, 1439)),
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
