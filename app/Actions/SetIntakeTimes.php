<?php

namespace App\Actions;

use App\Enums\UserRole;
use App\Exceptions\PrescriptionNotChangeable;
use App\Models\Prescription;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Пациент выбирает время приёмов регулярного назначения врача, сразу для всех приёмов.
 * Данные должны быть уже проверены. Будущие неотмеченные плановые приёмы пересчитываются.
 */
class SetIntakeTimes
{
    public function __construct(private readonly SyncDoseEvents $syncDoseEvents) {}

    /**
     * @param  array<string, string>  $times  время суток приёма => местное время «08:00»
     * @return bool изменилось ли время хотя бы одного приёма
     *
     * @throws PrescriptionNotChangeable
     */
    public function handle(User $patient, Prescription $prescription, array $times): bool
    {
        if ($patient->role !== UserRole::Patient || $prescription->patient_id !== $patient->id) {
            throw new InvalidArgumentException('Время приёма выбирает пациент в своём назначении.');
        }

        return DB::transaction(function () use ($patient, $prescription, $times): bool {
            // Тот же порядок блокировок, что у врача: сначала пациент, потом назначение.
            $patient = User::query()->whereKey($patient->id)->lockForUpdate()->firstOrFail();
            $current = Prescription::query()->lockForUpdate()->findOrFail($prescription->id);

            if (! $current->acceptsPatientTimes($patient->localToday())) {
                throw new PrescriptionNotChangeable;
            }

            $changed = false;

            foreach ($current->intakes as $intake) {
                $time = $times[$intake->time_of_day->value]
                    ?? throw new InvalidArgumentException('Нужно время для каждого приёма назначения.');

                if ($intake->time !== $time) {
                    $intake->update(['time' => $time, 'time_set_at' => now()]);
                    $changed = true;
                }
            }

            if ($changed) {
                $this->syncDoseEvents->handle($patient);
            }

            return $changed;
        });
    }
}
