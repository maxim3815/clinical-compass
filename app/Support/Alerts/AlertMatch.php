<?php

namespace App\Support\Alerts;

use App\Models\AlertRule;
use App\Models\Assessment;

/**
 * Правило сработало на отправленных вместе опросниках: заполнение, к которому относится алерт
 * (первый опросник из условия), и ответы, на которые смотрело условие.
 *
 * @phpstan-import-type Evidence from \App\Models\Alert
 */
final readonly class AlertMatch
{
    /**
     * @param  list<Evidence>  $evidence
     */
    public function __construct(
        public AlertRule $rule,
        public Assessment $assessment,
        public array $evidence,
    ) {}
}
