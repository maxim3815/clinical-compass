<?php

namespace App\Support;

use App\Enums\PrescriptionKind;
use App\Models\Prescription;
use App\Models\PrescriptionIntake;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * Назначение для страниц врача и пациента. Нужны загруженные drug, strength, intakes и prescriber.
 * У препарата, добавленного пациентом, дозировки нет, а доза одна на все приёмы и записана текстом.
 * Название пациент видит то, что выбрал сам («Кордарон»), врач — препарат и в скобках выбранное
 * название («Амиодарон (Кордарон)»).
 */
final class PrescriptionPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function present(Prescription $prescription, CarbonImmutable $today, bool $forPatient = false): array
    {
        $status = $prescription->status($today);
        $ownDose = $prescription->dose;

        return [
            'id' => $prescription->id,
            'source' => $prescription->source->value,
            'drug' => [
                'code' => $prescription->drug?->code,
                'name' => $forPatient ? $prescription->drugName() : $prescription->doctorDrugName(),
                // catalog — справочник, known — список препаратов от других врачей, null — «другой» препарат.
                'kind' => $prescription->drug?->kind->value,
                // Врачу у препарата списка — почему он в списке, без пометок для сверки медиком.
                ...$forPatient ? [] : ['reason' => $prescription->drug?->publicReason()],
            ],
            'strength' => $prescription->strength?->label(),
            'dose' => $ownDose,
            'kind' => $prescription->kind->value,
            'kind_label' => $prescription->kind->label(),
            'intakes' => PrescriptionIntake::sortByTimeOfDay($prescription->intakes)
                ->map(fn (PrescriptionIntake $intake): array => [
                    'time_of_day' => $intake->time_of_day->label(),
                    'dose' => $ownDose === null ? $prescription->doseLabel($intake->tablets) : null,
                    'time' => $intake->time === null ? null : self::clock($intake->time),
                ])
                ->all(),
            'as_needed' => $prescription->kind === PrescriptionKind::AsNeeded ? [
                'dose' => $prescription->doseLabel($prescription->tablets),
                'limit' => $prescription->max_daily_doses === null ? null : self::dailyLimit($prescription->max_daily_doses),
            ] : null,
            'instruction' => $prescription->instruction,
            'starts_on' => $prescription->starts_on->format('d.m.Y'),
            'ends_on' => $prescription->ends_on?->format('d.m.Y'),
            'status' => $status->value,
            'status_label' => $status->label(),
            'prescriber' => $prescription->prescriber?->name,
            'prescribed_by' => $prescription->prescribed_by,
            'closed_at' => $prescription->closed_at === null ? null : DisplayTime::format($prescription->closed_at),
            'cancel_reason' => $prescription->cancel_reason?->label(),
            'cancel_comment' => $prescription->cancel_comment,
            'can_change' => $prescription->isChangeableByDoctor($today),
        ];
    }

    /**
     * Действующие и будущие назначения по препарату, сначала регулярные, потом по дате начала.
     *
     * @param  Collection<int, Prescription>  $prescriptions
     * @return array<int, array<string, mixed>>
     */
    public static function presentList(Collection $prescriptions, CarbonImmutable $today, bool $forPatient = false): array
    {
        return $prescriptions
            ->sortBy(fn (Prescription $prescription): array => [
                $forPatient ? $prescription->drugName() : $prescription->doctorDrugName(),
                $prescription->kind === PrescriptionKind::Regular ? 0 : 1,
                $prescription->starts_on->toDateString(),
            ])
            ->map(fn (Prescription $prescription): array => self::present($prescription, $today, $forPatient))
            ->values()
            ->all();
    }

    /**
     * Время приёма без ведущего нуля: «8:00».
     */
    private static function clock(string $time): string
    {
        return (int) substr($time, 0, 2).substr($time, 2);
    }

    /**
     * «Не больше 3 раз в сутки».
     */
    private static function dailyLimit(int $doses): string
    {
        return 'Не больше '.$doses.' '.Plural::choose($doses, ['раза', 'раз', 'раз']).' в сутки';
    }
}
