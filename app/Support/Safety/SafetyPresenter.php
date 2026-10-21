<?php

namespace App\Support\Safety;

use App\Models\Prescription;
use App\Models\SafetyFinding;
use App\Models\SafetyRule;
use App\Models\User;
use App\Support\DisplayTime;

/**
 * Проверка безопасности для страниц врача: блок в форме назначения и плашки в карточке пациента.
 */
final readonly class SafetyPresenter
{
    public function __construct(private SafetyEngine $engine) {}

    /**
     * Блок проверки в форме назначения.
     *
     * @return array<string, mixed>
     */
    public static function report(SafetyReport $report): array
    {
        $present = fn (RuleResult $result): array => [
            'key' => $result->key(),
            ...self::rule($result->rule),
            'target' => $result->target->label(),
            'evidence' => $result->evidence,
            'decided' => isset($report->decided[$result->key()]),
        ];

        return [
            'has_rules' => $report->hasRules,
            'findings' => array_map($present, $report->results),
            'caused' => array_map($present, $report->caused),
            'unchecked' => $report->unchecked,
            'unknown_drugs' => $report->unknownDrugs,
            'requires_justification' => $report->requiringJustification() !== [],
        ];
    }

    /**
     * Находки и «Не проверено» по каждому препарату, который пациент принимает, для карточки.
     *
     * @return array<int, array<string, mixed>> id назначения => находки
     */
    public function card(User $patient): array
    {
        $data = PatientSafetyData::load($patient);
        $findings = SafetyFinding::query()
            ->where('patient_id', $patient->id)
            ->open()
            ->with(['rule', 'decidedBy'])
            ->orderBy('id')
            ->get()
            ->groupBy('prescription_id');
        $names = [];

        foreach ($data->medications as $medication) {
            $names[(int) $medication->prescriptionId] = $medication->label();
        }

        $unknown = array_values(array_map(
            fn (Medication $medication): string => $medication->name,
            array_filter($data->medications, fn (Medication $medication): bool => $medication->drug === null),
        ));

        $card = [];

        foreach ($data->medications as $medication) {
            $id = (int) $medication->prescriptionId;

            if (! $this->engine->hasRules($medication)) {
                continue;
            }

            $check = $this->engine->check($data, $medication, $data->othersThan($medication));

            $card[$id] = [
                'findings' => $findings->get($id, collect())
                    ->map(fn (SafetyFinding $finding): array => self::finding($finding, $names))
                    ->values()
                    ->all(),
                'unchecked' => $check->unchecked,
                'unknown_drugs' => array_values(array_diff($unknown, [$medication->name])),
            ];
        }

        return $card;
    }

    /**
     * @param  array<int, string>  $names  id назначения => название для врача
     * @return array<string, mixed>
     */
    public static function finding(SafetyFinding $finding, array $names): array
    {
        return [
            'id' => $finding->id,
            ...self::rule($finding->rule),
            'level' => $finding->level->value,
            'level_label' => $finding->level->label(),
            'evidence' => $finding->evidence,
            'awaits_decision' => $finding->awaitsDecision(),
            // С чем сочетание: решение «Продолжить» относится и к находке у второго препарата.
            'related' => array_values(array_filter(array_map(fn (int $id): ?string => $names[$id] ?? null, $finding->related_prescription_ids))),
            'opened_at' => DisplayTime::format($finding->opened_at),
            'decision' => $finding->decided_at === null ? null : [
                'kind_label' => $finding->decision_kind?->label(),
                'text' => $finding->decision_text,
                'by' => $finding->decidedBy?->name,
                'at' => DisplayTime::format($finding->decided_at),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function rule(SafetyRule $rule): array
    {
        return [
            'rule' => $rule->code,
            'type_label' => $rule->type->label(),
            'level' => $rule->level->value,
            'level_label' => $rule->level->label(),
            'text' => $rule->text,
            'advice' => $rule->advice,
            'source' => $rule->source(),
        ];
    }

    /**
     * Есть ли у назначения красная находка без решения: для подсказки в форме изменения.
     */
    public static function awaitsDecision(Prescription $prescription): bool
    {
        return SafetyFinding::query()->awaitingDecision()->where('prescription_id', $prescription->id)->exists();
    }
}
