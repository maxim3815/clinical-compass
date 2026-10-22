<?php

namespace App\Support\Alerts;

use App\Models\CareRelationship;
use App\Models\User;
use Illuminate\Database\Query\Builder;

/**
 * Пациенты с открытым алертом: они в счётчике у «Пациентов», пока врач не закрыл алерт решением.
 */
final class AlertSignals
{
    /**
     * @return list<int>
     */
    public static function patientIds(User $doctor): array
    {
        return array_values(CareRelationship::query()
            ->active()
            ->where('doctor_id', $doctor->id)
            ->whereExists(fn (Builder $query) => $query
                ->selectRaw('1')
                ->from('alerts')
                ->whereColumn('alerts.patient_id', 'care_relationships.patient_id')
                ->whereNull('alerts.closed_at'))
            ->pluck('patient_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all());
    }
}
