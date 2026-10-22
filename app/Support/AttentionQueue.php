<?php

namespace App\Support;

use App\Models\CareRelationship;
use App\Models\User;
use App\Support\Adherence\AdherenceSignals;
use App\Support\Alerts\AlertPresenter;
use App\Support\Safety\ClinicalProfilePresenter;
use App\Support\Safety\SafetySignals;

/**
 * Очередь «Требуют внимания» над списком пациентов: открытый алерт, противопоказание без решения
 * врача, сигнал о приверженности и непрочитанные сообщения. Причины берутся из тех же расчётов,
 * что отметки в списке и счётчики в меню.
 */
final class AttentionQueue
{
    /**
     * Сначала алерты, затем противопоказания, сигналы с новыми пропусками, остальные сигналы
     * и только сообщения; внутри — по ФИО.
     *
     * @return list<array<string, mixed>>
     */
    public static function present(User $doctor): array
    {
        $patientIds = CareRelationship::query()
            ->active()
            ->where('doctor_id', $doctor->id)
            ->pluck('patient_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        $alerts = AlertPresenter::openByPatient($doctor);
        $contraindications = SafetySignals::awaitingDrugs($patientIds);
        $adherence = AdherenceSignals::raised($doctor);
        $unseen = array_flip(AdherenceSignals::unseenPatientIds($doctor));
        $unread = UnreadMessages::byPatient($doctor);

        $ids = array_values(array_unique([...array_keys($alerts), ...array_keys($contraindications), ...array_keys($adherence), ...array_keys($unread)]));

        if ($ids === []) {
            return [];
        }

        $diagnoses = ClinicalProfilePresenter::mainDiagnoses($ids);

        return array_values(User::query()
            ->whereKey($ids)
            ->with('patientProfile')
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            // Сортировка устойчивая: внутри одной причины остаётся порядок по ФИО.
            ->sortBy(fn (User $patient): array => [
                isset($alerts[$patient->id]) ? 0 : 1,
                isset($contraindications[$patient->id]) ? 0 : 1,
                isset($unseen[$patient->id]) ? 0 : 1,
                isset($adherence[$patient->id]) ? 0 : 1,
            ])
            ->map(fn (User $patient): array => [
                'id' => $patient->id,
                'name' => $patient->name,
                'age' => $patient->patientProfile?->age(),
                'diagnosis' => $diagnoses[$patient->id] ?? null,
                // Открытые алерты: что сработало и принял ли врач.
                'alerts' => $alerts[$patient->id] ?? [],
                // Препараты с красной находкой без решения.
                'contraindications' => $contraindications[$patient->id] ?? [],
                'adherence' => ($adherence[$patient->id] ?? null)?->toArray(),
                // Сигнал с пропусками, которых врач ещё не видел: он в счётчике у «Пациентов».
                'new_misses' => isset($unseen[$patient->id]),
                'unread' => $unread[$patient->id] ?? 0,
            ])
            ->all());
    }
}
