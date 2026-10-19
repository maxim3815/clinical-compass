<?php

namespace Database\Factories;

use App\Enums\TimeOfDay;
use App\Models\Prescription;
use App\Models\PrescriptionIntake;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PrescriptionIntake>
 */
class PrescriptionIntakeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'prescription_id' => Prescription::factory(),
            'time_of_day' => TimeOfDay::Morning,
            'tablets' => '1',
        ];
    }

    /**
     * Пациент выбрал время приёма, по умолчанию сейчас.
     */
    public function at(string $time, mixed $setAt = null): static
    {
        return $this->state(fn (array $attributes) => [
            'time' => $time,
            'time_set_at' => $setAt ?? now(),
        ]);
    }
}
