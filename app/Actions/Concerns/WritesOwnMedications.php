<?php

namespace App\Actions\Concerns;

use App\Enums\PrescriptionKind;
use App\Enums\UserRole;
use App\Exceptions\PrescriptionNotChangeable;
use App\Models\Prescription;
use App\Models\PrescriptionIntake;
use App\Models\User;
use App\Support\OwnMedicationData;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Общее для добавления, изменения и прекращения препарата, который пациент ведёт сам.
 */
trait WritesOwnMedications
{
    private function ensurePatient(User $patient): void
    {
        if ($patient->role !== UserRole::Patient) {
            throw new InvalidArgumentException('Свои препараты добавляет только пациент.');
        }
    }

    /**
     * Строка пациента под блокировкой: тот же порядок, что у назначений врача и синхронизации,
     * поэтому проверка повтора назначения врача не пересекается с его назначением. Вызывать
     * внутри транзакции.
     */
    private function lockPatient(int $patientId): User
    {
        return User::query()->whereKey($patientId)->lockForUpdate()->firstOrFail();
    }

    /**
     * Свежая версия препарата под блокировкой, если пациент ещё может её менять.
     *
     * @throws PrescriptionNotChangeable
     */
    private function lockChangeable(User $patient, Prescription $prescription, CarbonImmutable $today): Prescription
    {
        if ($prescription->patient_id !== $patient->id) {
            throw new InvalidArgumentException('Пациент меняет только свои препараты.');
        }

        $current = Prescription::query()->lockForUpdate()->findOrFail($prescription->id);

        if (! $current->isChangeableByPatient($today)) {
            throw new PrescriptionNotChangeable;
        }

        return $current;
    }

    /**
     * Препарат из справочника, который на эти даты уже назначил врач тем же видом приёма,
     * пациент не добавляет: приём отмечается по назначению врача.
     *
     * @throws ValidationException
     */
    private function ensureNotPrescribedByDoctor(int $patientId, OwnMedicationData $data, CarbonImmutable $startsOn): void
    {
        if ($data->drug === null) {
            return;
        }

        $exists = Prescription::query()
            ->overlapping($patientId, $data->drug->id, $data->kind, $startsOn, $data->endsOn)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'drug_id' => match ($data->kind) {
                    PrescriptionKind::Regular => 'Врач уже назначил вам этот препарат на эти даты. Отмечайте приём в разделе «Назначил врач».',
                    PrescriptionKind::AsNeeded => 'Врач уже назначил вам этот препарат «по необходимости» на эти даты. Отмечайте приёмы по его назначению на экране «Сегодня».',
                },
            ]);
        }
    }

    /**
     * Поля версии из данных формы.
     *
     * @return array<string, mixed>
     */
    private function versionAttributes(OwnMedicationData $data): array
    {
        return [
            'drug_id' => $data->drug?->id,
            'drug_name' => $data->drug === null ? $data->drugName : null,
            'trade_name' => $data->drug === null ? null : $data->tradeName,
            'kind' => $data->kind,
            'dose' => $data->dose,
            'max_daily_doses' => $data->maxDailyDoses,
            'starts_on' => $data->startsOn->toDateString(),
            'ends_on' => $data->endsOn?->toDateString(),
            'prescribed_by' => $data->prescribedBy,
            'effective_at' => now(),
        ];
    }

    /**
     * Приёмы новой версии. Если время приёма не изменилось, переходит и момент его выбора.
     */
    private function createIntakes(Prescription $prescription, OwnMedicationData $data, ?Prescription $previous = null): void
    {
        $previousIntakes = $previous?->intakes->keyBy(fn (PrescriptionIntake $intake): string => $intake->time_of_day->value);

        foreach ($data->intakes as $timeOfDay => $time) {
            $carried = $previousIntakes?->get($timeOfDay);

            $prescription->intakes()->create([
                'time_of_day' => $timeOfDay,
                'time' => $time,
                'time_set_at' => $carried?->time === $time ? $carried->time_set_at : now(),
            ]);
        }
    }
}
