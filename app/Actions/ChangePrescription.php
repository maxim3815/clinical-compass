<?php

namespace App\Actions;

use App\Actions\Concerns\WritesPrescriptions;
use App\Enums\PrescriptionChangeOutcome;
use App\Enums\PrescriptionCloseReason;
use App\Enums\PrescriptionSource;
use App\Enums\PrescriptionStatus;
use App\Exceptions\PrescriptionNotChangeable;
use App\Models\Prescription;
use App\Models\PrescriptionIntake;
use App\Models\User;
use App\Support\PrescriptionData;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Врач меняет назначение. Ещё не начавшееся назначение исправляется на месте. У действующего
 * прежняя версия закрывается сейчас, а новая действует с сегодняшнего дня: плановые приёмы
 * и отметки остаются привязаны к версии, по которой были.
 */
class ChangePrescription
{
    use WritesPrescriptions;

    /**
     * @throws PrescriptionNotChangeable
     * @throws ValidationException
     */
    public function handle(User $doctor, Prescription $prescription, PrescriptionData $data): PrescriptionChangeOutcome
    {
        $this->ensureDoctor($doctor);

        if ($data->strength->drug_id !== $prescription->drug_id || $data->kind !== $prescription->kind) {
            throw new InvalidArgumentException('Препарат и вид приёма в назначении не меняются: для них нужно новое назначение.');
        }

        return DB::transaction(function () use ($doctor, $prescription, $data): PrescriptionChangeOutcome {
            $patient = $this->lockPatient($prescription->patient_id);
            $today = $patient->localToday();
            $current = $this->lockChangeable($prescription, $today);

            if (! $this->differs($current, $data)) {
                return PrescriptionChangeOutcome::Unchanged;
            }

            if ($current->status($today) === PrescriptionStatus::Upcoming) {
                $this->ensureNoOverlap($patient->id, $data, $data->startsOn, 'starts_on', $current->id);

                $current->update([
                    ...$this->versionAttributes($data),
                    'prescriber_id' => $doctor->id,
                ]);
                $this->syncIntakes($current, $data);

                return PrescriptionChangeOutcome::Edited;
            }

            $this->ensureNoOverlap($patient->id, $data, $today, 'ends_on', $current->id);

            $current->update([
                'closed_at' => now(),
                'closed_by_id' => $doctor->id,
                'close_reason' => PrescriptionCloseReason::Changed,
            ]);

            $next = Prescription::query()->create([
                ...$this->versionAttributes($data),
                'starts_on' => $today->toDateString(),
                'patient_id' => $patient->id,
                'source' => PrescriptionSource::Doctor,
                'prescriber_id' => $doctor->id,
                'previous_id' => $current->id,
            ]);
            $this->createIntakes($next, $data);

            return PrescriptionChangeOutcome::NewVersion;
        });
    }

    private function differs(Prescription $current, PrescriptionData $data): bool
    {
        $intakes = PrescriptionIntake::sortByTimeOfDay($current->intakes)
            ->mapWithKeys(fn (PrescriptionIntake $intake): array => [$intake->time_of_day->value => $intake->tablets])
            ->all();

        return $current->drug_strength_id !== $data->strength->id
            || $intakes !== $data->intakes
            || $current->tablets !== $data->tablets
            || $current->max_daily_doses !== $data->maxDailyDoses
            || $current->instruction !== $data->instruction
            || $current->starts_on->toDateString() !== $data->startsOn->toDateString()
            || $current->ends_on?->toDateString() !== $data->endsOn?->toDateString();
    }

    /**
     * Приёмы с тем же временем суток остаются теми же записями: к ним привязано время, выбранное пациентом.
     */
    private function syncIntakes(Prescription $prescription, PrescriptionData $data): void
    {
        $prescription->intakes()->whereNotIn('time_of_day', array_keys($data->intakes))->delete();

        foreach ($data->intakes as $timeOfDay => $tablets) {
            $prescription->intakes()->updateOrCreate(['time_of_day' => $timeOfDay], ['tablets' => $tablets]);
        }
    }
}
