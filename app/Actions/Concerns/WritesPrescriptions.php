<?php

namespace App\Actions\Concerns;

use App\Enums\PrescriptionKind;
use App\Enums\UserRole;
use App\Exceptions\PrescriptionNotChangeable;
use App\Models\Prescription;
use App\Models\PrescriptionIntake;
use App\Models\User;
use App\Support\PrescriptionData;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Общее для назначения, изменения и отмены назначения врачом.
 */
trait WritesPrescriptions
{
    private function ensureDoctor(User $doctor): void
    {
        if ($doctor->role !== UserRole::Doctor) {
            throw new InvalidArgumentException('Назначения ведёт только врач.');
        }
    }

    /**
     * Назначения одного пациента меняются по очереди, иначе два одновременных запроса
     * оба пройдут проверку пересечения. Вызывать внутри транзакции.
     */
    private function lockPatient(int $patientId): User
    {
        return User::query()->whereKey($patientId)->lockForUpdate()->firstOrFail();
    }

    /**
     * Свежая версия назначения под блокировкой, если врач ещё может её менять.
     *
     * @throws PrescriptionNotChangeable
     */
    private function lockChangeable(Prescription $prescription, CarbonImmutable $today): Prescription
    {
        $current = Prescription::query()->lockForUpdate()->findOrFail($prescription->id);

        if (! $current->isChangeableByDoctor($today)) {
            throw new PrescriptionNotChangeable;
        }

        return $current;
    }

    /**
     * Второе назначение врача на тот же препарат и вид с пересекающимися датами не допускается.
     *
     * @throws ValidationException
     */
    private function ensureNoOverlap(
        int $patientId,
        PrescriptionData $data,
        CarbonImmutable $startsOn,
        string $field,
        ?int $exceptId = null,
    ): void {
        $exists = Prescription::query()
            ->overlapping($patientId, $data->strength->drug_id, $data->kind, $startsOn, $data->endsOn)
            ->when($exceptId !== null, fn (Builder $query) => $query->whereKeyNot($exceptId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                $field => match ($data->kind) {
                    PrescriptionKind::Regular => 'У пациента уже есть регулярное назначение этого препарата на эти даты. Измените или отмените его.',
                    PrescriptionKind::AsNeeded => 'У пациента уже есть назначение этого препарата «по необходимости» на эти даты. Измените или отмените его.',
                },
            ]);
        }
    }

    /**
     * Поля версии назначения из данных формы.
     *
     * @return array<string, mixed>
     */
    private function versionAttributes(PrescriptionData $data): array
    {
        return [
            'drug_id' => $data->strength->drug_id,
            'drug_strength_id' => $data->strength->id,
            'kind' => $data->kind,
            'tablets' => $data->tablets,
            'max_daily_doses' => $data->maxDailyDoses,
            'instruction' => $data->instruction,
            'starts_on' => $data->startsOn->toDateString(),
            'ends_on' => $data->endsOn?->toDateString(),
            'effective_at' => now(),
        ];
    }

    /**
     * Приёмы новой версии. Время, выбранное пациентом в прежней версии, переходит к приёму
     * с тем же временем суток. Новые приёмы ждут, пока пациент выберет время.
     */
    private function createIntakes(Prescription $prescription, PrescriptionData $data, ?Prescription $previous = null): void
    {
        $previousIntakes = $previous?->intakes->keyBy(fn (PrescriptionIntake $intake): string => $intake->time_of_day->value);

        foreach ($data->intakes as $timeOfDay => $tablets) {
            $carried = $previousIntakes?->get($timeOfDay);

            $prescription->intakes()->create([
                'time_of_day' => $timeOfDay,
                'tablets' => $tablets,
                'time' => $carried?->time,
                'time_set_at' => $carried?->time_set_at,
            ]);
        }
    }
}
