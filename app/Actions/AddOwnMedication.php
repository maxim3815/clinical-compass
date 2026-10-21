<?php

namespace App\Actions;

use App\Actions\Concerns\WritesOwnMedications;
use App\Enums\PrescriptionSource;
use App\Models\AuditLog;
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

    public function __construct(
        private readonly SyncDoseEvents $syncDoseEvents,
        private readonly SyncSafetyFindings $syncSafetyFindings,
    ) {}

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
            // Сочетание с назначениями врача видит только врач: находка появляется у него.
            $this->syncSafetyFindings->handle($patient);

            AuditLog::record($patient, 'own_medication.added', $patient, $prescription, null, $prescription->auditSnapshot());

            return $prescription;
        });
    }
}
