<?php

namespace App\Actions;

use App\Actions\Concerns\EditsClinicalProfile;
use App\Models\AuditLog;
use App\Models\Observation;
use App\Models\ObservationType;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Врач вносит факт о пациенте. Прежние записи не меняются: текущим считается последний по дате.
 * Значение должно быть уже проверено по виду факта.
 */
class RecordObservation
{
    use EditsClinicalProfile;

    public function __construct(private readonly SyncSafetyFindings $syncSafetyFindings) {}

    public function handle(
        User $doctor,
        User $patient,
        ObservationType $type,
        bool|float|string $value,
        ?string $comment,
        CarbonImmutable $observedOn,
    ): Observation {
        $this->ensureDoctorAndPatient($doctor, $patient);

        return DB::transaction(function () use ($doctor, $patient, $type, $value, $comment, $observedOn): Observation {
            $patient = $this->lockPatient($patient);

            $observation = $patient->observations()->create([
                'observation_type_id' => $type->id,
                $type->value_type->column() => $value,
                'comment' => $comment,
                'observed_on' => $observedOn->toDateString(),
                'recorded_by_id' => $doctor->id,
            ]);

            AuditLog::record($doctor, 'observation.recorded', $patient, $observation, null, [
                'type' => $type->code,
                'value' => $value,
                'comment' => $comment,
                'observed_on' => $observedOn->toDateString(),
            ]);
            $this->syncSafetyFindings->handle($patient);

            return $observation;
        });
    }
}
