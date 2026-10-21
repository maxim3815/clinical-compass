<?php

namespace App\Support\Safety;

use App\Enums\SafetyLevel;

/**
 * Проверка назначения, которое врач оформляет: находки у него самого, находки, которые оно
 * вызовет у других препаратов пациента, и чего не хватило правилам.
 */
final readonly class SafetyReport
{
    /**
     * @param  list<RuleResult>  $results  у оформляемого назначения
     * @param  list<RuleResult>  $caused  у других препаратов, появятся вместе с назначением
     * @param  list<string>  $unchecked
     * @param  list<string>  $unknownDrugs  препараты пациента не из справочника
     * @param  array<string, true>  $decided  ключи красных находок, по которым врач уже решил
     */
    public function __construct(
        public Medication $candidate,
        public array $results,
        public array $caused,
        public array $unchecked,
        public array $unknownDrugs,
        public bool $hasRules,
        public array $decided,
    ) {}

    /**
     * @return list<RuleResult>
     */
    public function all(): array
    {
        return [...$this->results, ...$this->caused];
    }

    /**
     * Красные находки без решения врача: из-за них нужно обоснование.
     *
     * @return list<RuleResult>
     */
    public function requiringJustification(): array
    {
        return array_values(array_filter(
            $this->all(),
            fn (RuleResult $result): bool => $result->rule->level === SafetyLevel::Contraindicated && ! isset($this->decided[$result->key()]),
        ));
    }

    /**
     * Находки, которые врач видел при назначении, для журнала аудита: правило в той версии,
     * которую он видел, и данные пациента.
     *
     * @return list<array<string, mixed>>
     */
    public function auditSnapshot(): array
    {
        return array_map(fn (RuleResult $result): array => [
            'prescription_id' => $result->target->prescriptionId,
            'rule' => $result->rule->snapshot(),
            'evidence' => $result->evidence,
        ], $this->all());
    }

    /**
     * @return list<string>
     */
    public function requiringKeys(): array
    {
        return array_map(fn (RuleResult $result): string => $result->key(), $this->requiringJustification());
    }
}
