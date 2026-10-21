<?php

namespace App\Actions;

use App\Actions\Concerns\EditsClinicalProfile;
use App\Models\AuditLog;
use App\Models\Diagnosis;
use App\Models\Icd10Code;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Врач ставит диагноз по МКБ-10: основной или сопутствующий. Действующий основной диагноз один,
 * и один код не ставится дважды.
 */
class AddDiagnosis
{
    use EditsClinicalProfile;

    public function __construct(private readonly SyncSafetyFindings $syncSafetyFindings) {}

    /**
     * @throws ValidationException
     */
    public function handle(User $doctor, User $patient, Icd10Code $code, bool $isMain, CarbonImmutable $establishedOn): Diagnosis
    {
        $this->ensureDoctorAndPatient($doctor, $patient);

        return DB::transaction(function () use ($doctor, $patient, $code, $isMain, $establishedOn): Diagnosis {
            $patient = $this->lockPatient($patient);
            $active = $patient->diagnoses()->active()->with('icd10')->get();

            if ($active->contains('icd10_code', $code->code)) {
                throw ValidationException::withMessages(['icd10_code' => 'Этот диагноз у пациента уже стоит.']);
            }

            $main = $active->firstWhere('is_main', true);

            if ($isMain && $main !== null) {
                throw ValidationException::withMessages([
                    'is_main' => "Основной диагноз уже есть: {$main->icd10->label()}. Снимите его или отметьте новый как сопутствующий.",
                ]);
            }

            $diagnosis = $patient->diagnoses()->create([
                'icd10_code' => $code->code,
                'is_main' => $isMain,
                'established_on' => $establishedOn->toDateString(),
                'created_by_id' => $doctor->id,
            ]);

            AuditLog::record($doctor, 'diagnosis.added', $patient, $diagnosis, null, $diagnosis->only(['icd10_code', 'is_main', 'established_on']));
            $this->syncSafetyFindings->handle($patient);

            return $diagnosis;
        });
    }
}
