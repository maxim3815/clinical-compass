<?php

namespace App\Support\Safety;

use App\Enums\PrescriptionSource;
use App\Enums\SafetyRuleType;
use App\Models\Diagnosis;
use App\Models\Icd10Code;
use App\Models\SafetyRule;

/**
 * Вычисляет правила препарата над данными пациента. Показания проверяются только у назначений
 * врача: у своих препаратов пациента это не решение врача.
 */
final class SafetyEngine
{
    /**
     * Действующие правила по id препарата, загружаются один раз.
     *
     * @var array<int, list<SafetyRule>>|null
     */
    private ?array $rules = null;

    public function __construct(private readonly RuleLanguage $language) {}

    /**
     * @param  list<Medication>  $others  что ещё принимает пациент
     */
    public function check(PatientSafetyData $data, Medication $target, array $others): TargetCheck
    {
        $results = [];
        $unchecked = [];

        foreach ($this->rulesOf($target) as $rule) {
            if ($rule->type === SafetyRuleType::Indication && $target->source !== PrescriptionSource::Doctor) {
                continue;
            }

            $evaluation = new RuleEvaluation($data, $target, $others);

            try {
                $fired = $rule->type === SafetyRuleType::Indication
                    ? $this->outsideIndications($rule, $data, $evaluation)
                    : $this->language->evaluateFor((string) $rule->condition, $evaluation);
            } catch (MissingData $missing) {
                $unchecked[] = $missing->label;

                continue;
            }

            if ($fired) {
                $results[] = new RuleResult($rule, $target, $evaluation->evidence, $evaluation->relatedPrescriptionIds, $evaluation->withCandidate);
            }
        }

        return new TargetCheck($target, $results, array_values(array_unique($unchecked)));
    }

    /**
     * Есть ли у препарата правила: для препарата не из справочника проверять нечего.
     */
    public function hasRules(Medication $target): bool
    {
        return $this->rulesOf($target) !== [];
    }

    /**
     * Ни один действующий диагноз не входит в показания. Без диагноза показания не проверить.
     *
     * @throws MissingData
     */
    private function outsideIndications(SafetyRule $rule, PatientSafetyData $data, RuleEvaluation $evaluation): bool
    {
        if ($data->diagnoses->isEmpty()) {
            throw new MissingData('диагноз');
        }

        $covered = $data->diagnoses->contains(fn (Diagnosis $diagnosis): bool => collect($rule->icd10_codes ?? [])
            ->contains(fn (string $code): bool => Icd10Code::covers($code, $diagnosis->icd10_code)));

        if ($covered) {
            return false;
        }

        foreach ($data->diagnoses as $diagnosis) {
            $evaluation->evidence[] = 'Диагноз '.$diagnosis->icd10->label();
        }

        return true;
    }

    /**
     * @return list<SafetyRule>
     */
    private function rulesOf(Medication $target): array
    {
        if ($this->rules === null) {
            $this->rules = [];

            foreach (SafetyRule::query()->active()->orderBy('position')->get() as $rule) {
                $this->rules[$rule->drug_id][] = $rule;
            }
        }

        return $target->drug === null ? [] : $this->rules[$target->drug->id] ?? [];
    }
}
