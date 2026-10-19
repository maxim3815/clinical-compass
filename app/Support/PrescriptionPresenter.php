<?php

namespace App\Support;

use App\Enums\PrescriptionKind;
use App\Models\Prescription;
use App\Models\PrescriptionIntake;
use Carbon\CarbonImmutable;

/**
 * Назначение для страниц врача и пациента. Нужны загруженные drug, strength, intakes и prescriber.
 */
final class PrescriptionPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function present(Prescription $prescription, CarbonImmutable $today): array
    {
        $status = $prescription->status($today);
        $mg = $prescription->strength->mg;

        return [
            'id' => $prescription->id,
            'drug' => [
                'code' => $prescription->drug->code,
                'name' => $prescription->drug->name,
            ],
            'strength' => $prescription->strength->label(),
            'kind' => $prescription->kind->value,
            'kind_label' => $prescription->kind->label(),
            'intakes' => PrescriptionIntake::sortByTimeOfDay($prescription->intakes)
                ->map(fn (PrescriptionIntake $intake): array => [
                    'time_of_day' => $intake->time_of_day->label(),
                    'dose' => Dose::label($intake->tablets, $mg),
                ])
                ->all(),
            'as_needed' => $prescription->kind === PrescriptionKind::AsNeeded ? [
                'dose' => Dose::label((string) $prescription->tablets, $mg),
                'limit' => self::dailyLimit((int) $prescription->max_daily_doses),
            ] : null,
            'instruction' => $prescription->instruction,
            'starts_on' => $prescription->starts_on->format('d.m.Y'),
            'ends_on' => $prescription->ends_on?->format('d.m.Y'),
            'status' => $status->value,
            'status_label' => $status->label(),
            'prescriber' => $prescription->prescriber?->name,
            'closed_at' => $prescription->closed_at === null ? null : DisplayTime::format($prescription->closed_at),
            'cancel_reason' => $prescription->cancel_reason?->label(),
            'cancel_comment' => $prescription->cancel_comment,
            'can_change' => $prescription->isChangeableByDoctor($today),
        ];
    }

    /**
     * «Не больше 3 раз в сутки».
     */
    private static function dailyLimit(int $doses): string
    {
        return 'Не больше '.$doses.' '.Plural::choose($doses, ['раза', 'раз', 'раз']).' в сутки';
    }
}
