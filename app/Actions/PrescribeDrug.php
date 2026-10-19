<?php

namespace App\Actions;

use App\Actions\Concerns\WritesPrescriptions;
use App\Enums\PrescriptionSource;
use App\Enums\UserRole;
use App\Models\Prescription;
use App\Models\User;
use App\Support\PrescriptionData;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Врач назначает пациенту препарат из справочника. Данные должны быть уже проверены.
 */
class PrescribeDrug
{
    use WritesPrescriptions;

    public function __construct(private readonly SyncDoseEvents $syncDoseEvents) {}

    /**
     * @throws ValidationException
     */
    public function handle(User $doctor, User $patient, PrescriptionData $data): Prescription
    {
        $this->ensureDoctor($doctor);

        if ($patient->role !== UserRole::Patient) {
            throw new InvalidArgumentException('Назначить препарат можно только пациенту.');
        }

        return DB::transaction(function () use ($doctor, $patient, $data): Prescription {
            $this->lockPatient($patient->id);
            $this->ensureNoOverlap($patient->id, $data, $data->startsOn, 'drug_id');

            $prescription = Prescription::query()->create([
                ...$this->versionAttributes($data),
                'patient_id' => $patient->id,
                'source' => PrescriptionSource::Doctor,
                'prescriber_id' => $doctor->id,
            ]);

            $this->createIntakes($prescription, $data);
            $this->syncDoseEvents->handle($patient);

            return $prescription;
        });
    }
}
