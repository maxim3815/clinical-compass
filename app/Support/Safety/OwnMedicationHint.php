<?php

namespace App\Support\Safety;

use App\Models\SafetyFinding;
use App\Models\User;

/**
 * Подсказка пациенту у своего препарата: рассказать врачу, что принимает его, и не прекращать
 * приём самому. Препарат участвует в красной находке без решения врача — она у него самого или
 * у другого назначения, где он среди связанных. Ни сочетания, ни причины пациент не видит.
 * Подсказка пропадает, когда врач решил по находке или находка закрылась.
 */
final class OwnMedicationHint
{
    /**
     * Назначения пациента, которые участвуют в красных находках без решения врача.
     *
     * @return list<int>
     */
    public static function prescriptionIds(User $patient): array
    {
        $ids = [];

        foreach (SafetyFinding::query()->awaitingDecision()->where('patient_id', $patient->id)->get() as $finding) {
            $ids[] = $finding->prescription_id;
            array_push($ids, ...$finding->related_prescription_ids);
        }

        return array_values(array_unique($ids));
    }
}
