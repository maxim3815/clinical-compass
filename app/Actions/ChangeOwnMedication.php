<?php

namespace App\Actions;

use App\Actions\Concerns\WritesOwnMedications;
use App\Enums\PrescriptionChangeOutcome;
use App\Enums\PrescriptionCloseReason;
use App\Enums\PrescriptionSource;
use App\Enums\PrescriptionStatus;
use App\Exceptions\PrescriptionNotChangeable;
use App\Models\AuditLog;
use App\Models\Prescription;
use App\Models\PrescriptionIntake;
use App\Models\User;
use App\Support\OwnMedicationData;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Пациент меняет препарат, который добавил сам, так же, как врач меняет назначение: ещё
 * не начавшийся исправляется на месте, у действующего прежняя версия закрывается сейчас,
 * а новая действует с сегодняшнего дня. Препарат и вид приёма не меняются.
 */
class ChangeOwnMedication
{
    use WritesOwnMedications;

    public function __construct(
        private readonly SyncDoseEvents $syncDoseEvents,
        private readonly SyncSafetyFindings $syncSafetyFindings,
    ) {}

    /**
     * @throws PrescriptionNotChangeable
     * @throws ValidationException
     */
    public function handle(User $patient, Prescription $prescription, OwnMedicationData $data): PrescriptionChangeOutcome
    {
        $this->ensurePatient($patient);

        if ($data->drug?->id !== $prescription->drug_id
            || ($data->drug === null && $data->drugName !== $prescription->drug_name)
            || $data->tradeName !== $prescription->trade_name
            || $data->kind !== $prescription->kind) {
            throw new InvalidArgumentException('Препарат и вид приёма не меняются: для них нужно добавить препарат заново.');
        }

        return DB::transaction(function () use ($patient, $prescription, $data): PrescriptionChangeOutcome {
            $patient = $this->lockPatient($patient->id);
            $today = $patient->localToday();
            $current = $this->lockChangeable($patient, $prescription, $today);

            if (! $this->differs($current, $data)) {
                return PrescriptionChangeOutcome::Unchanged;
            }

            $before = $current->auditSnapshot();

            if ($current->status($today) === PrescriptionStatus::Upcoming) {
                $this->ensureNotPrescribedByDoctor($patient->id, $data, $data->startsOn);

                $current->update($this->versionAttributes($data));
                $this->syncIntakes($current, $data);
                $this->syncDoseEvents->handle($patient);
                $this->syncSafetyFindings->handle($patient);

                AuditLog::record($patient, 'own_medication.changed', $patient, $current, $before, $current->auditSnapshot());

                return PrescriptionChangeOutcome::Edited;
            }

            $this->ensureNotPrescribedByDoctor($patient->id, $data, $today);

            $current->update([
                'closed_at' => now(),
                'closed_by_id' => $patient->id,
                'close_reason' => PrescriptionCloseReason::Changed,
            ]);

            $next = Prescription::query()->create([
                ...$this->versionAttributes($data),
                'starts_on' => $today->toDateString(),
                'patient_id' => $patient->id,
                'source' => PrescriptionSource::Patient,
                'previous_id' => $current->id,
            ]);
            $this->createIntakes($next, $data, $current);
            $this->syncDoseEvents->handle($patient);
            $this->syncSafetyFindings->handle($patient);

            AuditLog::record($patient, 'own_medication.changed', $patient, $next, $before, $next->auditSnapshot());

            return PrescriptionChangeOutcome::NewVersion;
        });
    }

    private function differs(Prescription $current, OwnMedicationData $data): bool
    {
        $intakes = PrescriptionIntake::sortByTimeOfDay($current->intakes)
            ->mapWithKeys(fn (PrescriptionIntake $intake): array => [$intake->time_of_day->value => $intake->time])
            ->all();

        return $current->dose !== $data->dose
            || $intakes !== $data->intakes
            || $current->max_daily_doses !== $data->maxDailyDoses
            || $current->prescribed_by !== $data->prescribedBy
            || $current->starts_on->toDateString() !== $data->startsOn->toDateString()
            || $current->ends_on?->toDateString() !== $data->endsOn?->toDateString();
    }

    /**
     * Приёмы с тем же временем суток остаются теми же записями. Момент выбора времени
     * обновляется, только если время изменилось.
     */
    private function syncIntakes(Prescription $prescription, OwnMedicationData $data): void
    {
        $prescription->intakes()->whereNotIn('time_of_day', array_keys($data->intakes))->delete();
        $existing = $prescription->intakes()->get()->keyBy(fn (PrescriptionIntake $intake): string => $intake->time_of_day->value);

        foreach ($data->intakes as $timeOfDay => $time) {
            $intake = $existing->get($timeOfDay);

            if ($intake === null) {
                $prescription->intakes()->create(['time_of_day' => $timeOfDay, 'time' => $time, 'time_set_at' => now()]);
            } elseif ($intake->time !== $time) {
                $intake->update(['time' => $time, 'time_set_at' => now()]);
            }
        }
    }
}
