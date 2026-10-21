<?php

namespace App\Support\Safety;

/**
 * Итог проверки одного препарата: сработавшие правила и данные, которых правилам не хватило.
 */
final readonly class TargetCheck
{
    /**
     * @param  list<RuleResult>  $results
     * @param  list<string>  $unchecked  «СКФ», «пол», «диагноз»
     */
    public function __construct(
        public Medication $target,
        public array $results,
        public array $unchecked,
    ) {}
}
