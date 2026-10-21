<?php

namespace App\Support\Safety;

use App\Enums\ObservationValueType;
use App\Models\Diagnosis;
use App\Models\Observation;
use App\Models\ObservationType;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * Клинический профиль пациента для врача: пол, диагнозы и факты с датой и автором.
 */
final class ClinicalProfilePresenter
{
    /**
     * Блок в карточке: пол, действующие диагнозы и текущее значение каждого внесённого факта.
     *
     * @return array<string, mixed>
     */
    public static function present(User $patient): array
    {
        $sex = $patient->patientProfile()->first()?->sex;
        $types = ObservationType::query()->orderBy('position')->get();
        $latest = self::observations($patient)->keyBy('observation_type_id');

        return [
            'sex' => $sex?->value,
            'sex_label' => $sex?->label(),
            'diagnoses' => $patient->diagnoses()->active()->with(['icd10', 'createdBy'])->orderByDesc('is_main')->orderBy('id')->get()
                ->map(fn (Diagnosis $diagnosis): array => self::diagnosis($diagnosis))
                ->all(),
            'facts' => $types
                ->filter(fn (ObservationType $type): bool => $latest->has($type->id))
                ->map(fn (ObservationType $type): array => self::observation($latest[$type->id]))
                ->values()
                ->all(),
        ];
    }

    /**
     * Страница правки: всё из блока, снятые диагнозы, виды фактов и история значений по каждому виду.
     *
     * @return array<string, mixed>
     */
    public static function edit(User $patient): array
    {
        $history = self::observations($patient)->reverse()->groupBy('observation_type_id');

        return [
            ...self::present($patient),
            'resolved_diagnoses' => $patient->diagnoses()->whereNotNull('resolved_on')->with(['icd10', 'createdBy', 'resolvedBy'])->orderByDesc('resolved_on')->get()
                ->map(fn (Diagnosis $diagnosis): array => self::diagnosis($diagnosis))
                ->all(),
            'types' => ObservationType::query()->active()->orderBy('position')->get()
                ->map(fn (ObservationType $type): array => [
                    'id' => $type->id,
                    'code' => $type->code,
                    'name' => $type->name,
                    'value_type' => $type->value_type->value,
                    'unit' => $type->unit,
                    'min' => $type->min === null ? null : (float) $type->min,
                    'max' => $type->max === null ? null : (float) $type->max,
                    'options' => $type->value_type === ObservationValueType::Choice ? $type->options : null,
                    'female_only' => $type->female_only,
                    'history' => $history->get($type->id, collect())
                        ->map(fn (Observation $observation): array => self::observation($observation))
                        ->values()
                        ->all(),
                ])
                ->all(),
        ];
    }

    /**
     * Действующий основной диагноз каждого из пациентов: по нему врач узнаёт пациента в списке.
     *
     * @param  array<int, int>  $patientIds
     * @return array<int, array{code: string, name: string}> id пациента => диагноз
     */
    public static function mainDiagnoses(array $patientIds): array
    {
        return Diagnosis::query()
            ->active()
            ->where('is_main', true)
            ->whereIn('patient_id', $patientIds)
            ->with('icd10')
            ->get()
            ->mapWithKeys(fn (Diagnosis $diagnosis): array => [
                $diagnosis->patient_id => ['code' => $diagnosis->icd10_code, 'name' => $diagnosis->icd10->name],
            ])
            ->all();
    }

    /**
     * Записи фактов по порядку: последняя по дате факта — текущая.
     *
     * @return Collection<int, Observation>
     */
    private static function observations(User $patient): Collection
    {
        return $patient->observations()->with(['type', 'recordedBy'])->orderBy('observed_on')->orderBy('id')->get();
    }

    /**
     * @return array<string, mixed>
     */
    private static function diagnosis(Diagnosis $diagnosis): array
    {
        return [
            'id' => $diagnosis->id,
            'code' => $diagnosis->icd10_code,
            'name' => $diagnosis->icd10->name,
            'is_main' => $diagnosis->is_main,
            'established_on' => $diagnosis->established_on->format('d.m.Y'),
            'created_by' => $diagnosis->createdBy->name,
            'resolved_on' => $diagnosis->resolved_on?->format('d.m.Y'),
            'resolved_by' => $diagnosis->resolvedBy?->name,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function observation(Observation $observation): array
    {
        return [
            'id' => $observation->id,
            'code' => $observation->type->code,
            'name' => $observation->type->name,
            'value' => $observation->type->format($observation->value()),
            'comment' => $observation->comment,
            'observed_on' => $observation->observed_on->format('d.m.Y'),
            'recorded_by' => $observation->recordedBy->name,
        ];
    }
}
