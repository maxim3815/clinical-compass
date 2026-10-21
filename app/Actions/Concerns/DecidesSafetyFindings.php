<?php

namespace App\Actions\Concerns;

use App\Models\AuditLog;
use App\Models\SafetyFinding;
use App\Support\Safety\SafetyDecision;

/**
 * Решение врача по красной находке: пишется в находку и в журнал аудита со снимком правила.
 */
trait DecidesSafetyFindings
{
    private function decide(SafetyFinding $finding, SafetyDecision $decision): void
    {
        $finding->fill([
            'decided_at' => now(),
            'decided_by_id' => $decision->doctor->id,
            'decision_kind' => $decision->kind,
            'decision_text' => $decision->text,
        ])->save();

        $finding->loadMissing(['rule', 'patient']);

        AuditLog::record($decision->doctor, 'safety_finding.decided', $finding->patient, $finding, null, [
            'prescription_id' => $finding->prescription_id,
            'rule' => $finding->rule->snapshot(),
            'level' => $finding->level->value,
            'evidence' => $finding->evidence,
            'decision_kind' => $decision->kind->value,
            'decision_text' => $decision->text,
        ]);
    }
}
