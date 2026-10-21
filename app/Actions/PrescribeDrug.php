<?php

namespace App\Actions;

use App\Actions\Concerns\WritesPrescriptions;
use App\Enums\PrescriptionSource;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Prescription;
use App\Models\User;
use App\Support\PrescriptionData;
use App\Support\Safety\SafetyAcknowledgement;
use App\Support\Safety\SafetyCheck;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Врач назначает пациенту препарат из справочника. Данные должны быть уже проверены.
 * Проверка безопасности повторяется здесь же, под блокировкой пациента: при красной находке
 * нужно обоснование, а находка, которой врач не видел в форме, не даёт сохранить.
 */
class PrescribeDrug
{
    use WritesPrescriptions;

    public function __construct(
        private readonly SyncDoseEvents $syncDoseEvents,
        private readonly SafetyCheck $safetyCheck,
        private readonly SyncSafetyFindings $syncSafetyFindings,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(User $doctor, User $patient, PrescriptionData $data, ?SafetyAcknowledgement $acknowledgement = null): Prescription
    {
        $this->ensureDoctor($doctor);

        if ($patient->role !== UserRole::Patient) {
            throw new InvalidArgumentException('Назначить препарат можно только пациенту.');
        }

        return DB::transaction(function () use ($doctor, $patient, $data, $acknowledgement): Prescription {
            $this->lockPatient($patient->id);
            $this->ensureNoOverlap($patient->id, $data, $data->startsOn, 'drug_id');

            $report = $this->safetyCheck->forCandidate($patient, $data->strength->drug);
            $decision = ($acknowledgement ?? new SafetyAcknowledgement)->confirm($report, $doctor);

            $prescription = Prescription::query()->create([
                ...$this->versionAttributes($data),
                'patient_id' => $patient->id,
                'source' => PrescriptionSource::Doctor,
                'prescriber_id' => $doctor->id,
            ]);

            $this->createIntakes($prescription, $data);
            $this->syncDoseEvents->handle($patient);
            $this->syncSafetyFindings->handle($patient, $decision?->forPrescription($prescription->id));

            AuditLog::record($doctor, 'prescription.prescribed', $patient, $prescription, null, [
                ...$prescription->auditSnapshot(),
                'safety' => $report->auditSnapshot(),
                'justification' => $decision?->text,
            ]);

            return $prescription;
        });
    }
}
