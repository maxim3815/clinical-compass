<?php

namespace App\Support\Safety;

use App\Models\SafetyRule;

/**
 * Правило сработало на препарате: на каких данных и с какими назначениями сочетание.
 */
final readonly class RuleResult
{
    /**
     * @param  list<string>  $evidence
     * @param  list<int>  $relatedPrescriptionIds
     */
    public function __construct(
        public SafetyRule $rule,
        public Medication $target,
        public array $evidence,
        public array $relatedPrescriptionIds,
        public bool $withCandidate,
    ) {}

    /**
     * Ключ находки для формы назначения: правило и назначение, у неоформленного назначения «new».
     */
    public function key(): string
    {
        return self::keyOf($this->rule->id, $this->target->prescriptionId);
    }

    public static function keyOf(int $ruleId, ?int $prescriptionId): string
    {
        return $ruleId.'@'.($prescriptionId ?? 'new');
    }
}
