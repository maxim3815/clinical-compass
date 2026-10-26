<?php

namespace App\Support\Safety;

use App\Models\CareRelationship;
use App\Models\Prescription;
use App\Models\SafetyFinding;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;

/**
 * Отметка в списке пациентов: красная находка, по которой врач ещё не решил. Держится до решения
 * врача, открытие карточки её не снимает.
 */
final class SafetySignals
{
    /**
     * Пациенты врача с красной находкой без решения.
     *
     * @return list<int>
     */
    public static function patientIds(User $doctor): array
    {
        return array_values(CareRelationship::query()
            ->active()
            ->where('doctor_id', $doctor->id)
            ->whereExists(fn (Builder $query) => $query
                ->selectRaw('1')
                ->from('safety_findings')
                ->whereColumn('safety_findings.patient_id', 'care_relationships.patient_id')
                ->whereNull('safety_findings.closed_at')
                ->whereNull('safety_findings.decided_at')
                ->where('safety_findings.level', 'contraindicated'))
            ->pluck('patient_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all());
    }

    /**
     * Сколько красных находок без решения у каждого из пациентов.
     *
     * @param  Collection<int, int>  $patientIds
     * @return array<int, int> id пациента => число; пациента без таких находок нет
     */
    public static function awaitingCounts(Collection $patientIds): array
    {
        return SafetyFinding::query()
            ->awaitingDecision()
            ->whereIn('patient_id', $patientIds)
            ->groupBy('patient_id')
            ->selectRaw('patient_id, count(*) AS findings')
            ->pluck('findings', 'patient_id')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();
    }

    /**
     * Препараты с красной находкой без решения у каждого из пациентов: причина в очереди
     * «Требуют внимания». Вместе с ними — препараты, с которыми сочетание: препарат из списка
     * от других врачей своих находок не получает, а изменилось лечение именно из-за него.
     *
     * @param  array<int, int>  $patientIds
     * @return array<int, list<string>> id пациента => названия препаратов по алфавиту
     */
    public static function awaitingDrugs(array $patientIds): array
    {
        $findings = SafetyFinding::query()
            ->awaitingDecision()
            ->whereIn('patient_id', $patientIds)
            ->with('prescription.drug')
            ->get();
        $related = Prescription::query()
            ->whereKey($findings->pluck('related_prescription_ids')->flatten()->unique()->all())
            ->with('drug')
            ->get()
            ->keyBy('id');

        return $findings
            ->groupBy('patient_id')
            ->map(fn (Collection $patientFindings): array => array_values($patientFindings
                ->flatMap(fn (SafetyFinding $finding): array => [
                    $finding->prescription,
                    ...array_filter(array_map(fn (int $id): ?Prescription => $related->get($id), $finding->related_prescription_ids)),
                ])
                ->map(fn (Prescription $prescription): string => $prescription->doctorDrugName())
                ->unique()
                ->sort()
                ->all()))
            ->all();
    }
}
