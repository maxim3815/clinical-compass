<?php

namespace Database\Factories;

use App\Enums\PrescriptionCancelReason;
use App\Enums\PrescriptionCloseReason;
use App\Enums\PrescriptionKind;
use App\Enums\PrescriptionSource;
use App\Models\DrugStrength;
use App\Models\Prescription;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Регулярное назначение врача без приёмов: приёмы добавляют через has(PrescriptionIntake::factory()).
 *
 * @extends Factory<Prescription>
 */
class PrescriptionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'patient_id' => User::factory()->patient(),
            'source' => PrescriptionSource::Doctor,
            'prescriber_id' => User::factory()->doctor(),
            'drug_strength_id' => DrugStrength::factory(),
            'drug_id' => fn (array $attributes) => DrugStrength::query()->whereKey($attributes['drug_strength_id'])->value('drug_id'),
            'kind' => PrescriptionKind::Regular,
            'instruction' => 'За 30 минут до еды.',
            'starts_on' => now()->toDateString(),
            'ends_on' => null,
            'effective_at' => now(),
        ];
    }

    /**
     * У препарата, добавленного пациентом, таблеток нет: доза записана текстом.
     */
    public function asNeeded(string $tablets = '1', ?int $maxDailyDoses = 3): static
    {
        return $this->state(fn (array $attributes) => [
            'kind' => PrescriptionKind::AsNeeded,
            'tablets' => $attributes['source'] === PrescriptionSource::Patient ? null : $tablets,
            'max_daily_doses' => $maxDailyDoses,
        ]);
    }

    /**
     * Препарат, который пациент добавил сам: без врача и дозировки, доза текстом. Препарат
     * справочника задаётся через for($drug, 'drug'), без него — «другой» препарат с названием.
     */
    public function addedByPatient(string $dose = '1 таблетка'): static
    {
        return $this->state(fn (array $attributes) => [
            'source' => PrescriptionSource::Patient,
            'prescriber_id' => null,
            'drug_strength_id' => null,
            'tablets' => null,
            'dose' => $dose,
            'instruction' => null,
            'drug_name' => fn (array $attributes) => $attributes['drug_id'] === null ? 'Фенибут' : null,
        ]);
    }

    public function cancelled(?PrescriptionCancelReason $reason = null, ?string $comment = null): static
    {
        return $this->state(fn (array $attributes) => [
            'closed_at' => now(),
            'closed_by_id' => $attributes['prescriber_id'],
            'close_reason' => PrescriptionCloseReason::Cancelled,
            'cancel_reason' => $reason,
            'cancel_comment' => $comment,
        ]);
    }

    /**
     * Версия, закрытая изменением назначения.
     */
    public function changed(): static
    {
        return $this->state(fn (array $attributes) => [
            'closed_at' => now(),
            'closed_by_id' => $attributes['prescriber_id'],
            'close_reason' => PrescriptionCloseReason::Changed,
        ]);
    }
}
