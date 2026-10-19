<?php

namespace App\Actions;

use App\Actions\Concerns\WritesOwnMedications;
use App\Enums\PrescriptionSource;
use App\Models\Prescription;
use App\Models\User;
use App\Support\OwnMedicationData;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Пациент добавляет препарат от другого врача, чтобы отмечать его приём. Данные должны быть
 * уже проверены. Время приёмов пациент выбирает сразу, поэтому плановые приёмы появляются сразу.
 */
class AddOwnMedication
{
    use WritesOwnMedications;

    public function __construct(private readonly SyncDoseEvents $syncDoseEvents) {}

    /**
     * @throws ValidationException
     */
    public function handle(User $patient, OwnMedicationData $data): Prescription
    {
        $this->ensurePatient($patient);

        return DB::transaction(function () use ($patient, $data): Prescription {
            $this->lockPatient($patient->id);
            $this->ensureNotPrescribedByDoctor($patient->id, $data, $data->startsOn);

            $prescription = Prescription::query()->create([
                ...$this->versionAttributes($data),
                'patient_id' => $patient->id,
                'source' => PrescriptionSource::Patient,
            ]);

            $this->createIntakes($prescription, $data);
            $this->syncDoseEvents->handle($patient);

            return $prescription;
        });
    }
}
