<?php

namespace App\Actions;

use App\Actions\Concerns\EditsClinicalProfile;
use App\Enums\Sex;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Врач указывает пол пациента: от него зависит, проверяются ли беременность и лактация.
 */
class SetPatientSex
{
    use EditsClinicalProfile;

    public function __construct(private readonly SyncSafetyFindings $syncSafetyFindings) {}

    public function handle(User $doctor, User $patient, ?Sex $sex): void
    {
        $this->ensureDoctorAndPatient($doctor, $patient);

        DB::transaction(function () use ($doctor, $patient, $sex): void {
            $patient = $this->lockPatient($patient);
            $profile = $patient->patientProfile()->firstOrFail();

            if ($profile->sex === $sex) {
                return;
            }

            $before = ['sex' => $profile->sex?->value];
            $profile->update(['sex' => $sex]);

            AuditLog::record($doctor, 'patient.sex_changed', $patient, $profile, $before, ['sex' => $sex?->value]);
            $this->syncSafetyFindings->handle($patient);
        });
    }
}
