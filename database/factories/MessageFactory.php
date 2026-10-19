<?php

namespace Database\Factories;

use App\Models\CareRelationship;
use App\Models\Message;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Без явного отправителя сообщение пишет пациент из связи.
 *
 * @extends Factory<Message>
 */
class MessageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'care_relationship_id' => CareRelationship::factory(),
            'sender_id' => fn (array $attributes): int => $this->careRelationship($attributes)->patient_id,
            'body' => fake()->sentence(),
        ];
    }

    /**
     * Сообщение от врача из связи.
     */
    public function fromDoctor(): static
    {
        // Замыкание: связь из for() подставляется уже после состояний.
        return $this->state([
            'sender_id' => fn (array $attributes): int => $this->careRelationship($attributes)->doctor_id,
        ]);
    }

    public function read(): static
    {
        return $this->state(fn (): array => ['read_at' => now()]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function careRelationship(array $attributes): CareRelationship
    {
        return CareRelationship::query()->whereKey($attributes['care_relationship_id'])->firstOrFail();
    }
}
