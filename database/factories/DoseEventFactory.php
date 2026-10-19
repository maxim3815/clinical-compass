<?php

namespace Database\Factories;

use App\Enums\DoseEventStatus;
use App\Enums\TimeOfDay;
use App\Models\DoseEvent;
use App\Models\Prescription;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Запланированный приём через час, окно 6 часов.
 *
 * @extends Factory<DoseEvent>
 */
class DoseEventFactory extends Factory
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
            'patient_id' => fn (array $attributes) => Prescription::query()->whereKey($attributes['prescription_id'])->value('patient_id'),
            'time_of_day' => TimeOfDay::Morning,
            'tablets' => '1',
            'local_date' => now()->toDateString(),
            'scheduled_at' => now()->addHour(),
            'window_closes_at' => now()->addHours(7),
            'status' => DoseEventStatus::Scheduled,
        ];
    }

    /**
     * Принят в плановое время и отмечен тогда же.
     */
    public function taken(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => DoseEventStatus::Taken,
            'taken_at' => $attributes['scheduled_at'],
            'marked_at' => $attributes['scheduled_at'],
        ]);
    }
}
