<?php

namespace App\Actions;

use App\Actions\Concerns\EditsClinicalProfile;
use App\Models\AuditLog;
use App\Models\Diagnosis;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Врач снимает диагноз с датой. Диагноз остаётся в истории.
 */
class ResolveDiagnosis
{
    use EditsClinicalProfile;

    public function __construct(private readonly SyncSafetyFindings $syncSafetyFindings) {}

    /**
     * @throws ValidationException
     */
    public function handle(User $doctor, Diagnosis $diagnosis, CarbonImmutable $resolvedOn): void
    {
        $patient = $diagnosis->patient()->firstOrFail();
        $this->ensureDoctorAndPatient($doctor, $patient);

        DB::transaction(function () use ($doctor, $patient, $diagnosis, $resolvedOn): void {
            $patient = $this->lockPatient($patient);
            $current = Diagnosis::query()->lockForUpdate()->findOrFail($diagnosis->id);

            if ($current->resolved_on !== null) {
                throw ValidationException::withMessages(['resolved_on' => 'Диагноз уже снят.']);
            }

            if ($resolvedOn->lt($current->established_on)) {
                throw ValidationException::withMessages(['resolved_on' => 'Диагноз нельзя снять раньше, чем он установлен.']);
            }

            $current->update(['resolved_on' => $resolvedOn->toDateString(), 'resolved_by_id' => $doctor->id]);

            AuditLog::record($doctor, 'diagnosis.resolved', $patient, $current, ['resolved_on' => null], ['resolved_on' => $resolvedOn->toDateString()]);
            $this->syncSafetyFindings->handle($patient);
        });
    }
}
