<?php

namespace Database\Factories;

use App\Models\AsNeededDose;
use App\Models\Prescription;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Приём «по необходимости» только что, одна таблетка.
 *
 * @extends Factory<AsNeededDose>
 */
class AsNeededDoseFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'prescription_id' => Prescription::factory()->asNeeded(),
            'patient_id' => fn (array $attributes) => Prescription::query()->whereKey($attributes['prescription_id'])->value('patient_id'),
            'tablets' => '1',
            'local_date' => now()->toDateString(),
            'taken_at' => now(),
        ];
    }
}
