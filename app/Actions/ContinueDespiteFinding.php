<?php

namespace App\Actions;

use App\Actions\Concerns\DecidesSafetyFindings;
use App\Enums\SafetyDecisionKind;
use App\Enums\UserRole;
use App\Events\PatientSignalsChanged;
use App\Models\SafetyFinding;
use App\Models\User;
use App\Support\Safety\SafetyDecision;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Врач продолжает приём вопреки красной находке и пишет обоснование. Если находка — сочетание
 * двух препаратов, и у второго та же находка на это сочетание без решения, решение относится
 * к обоим: это одно клиническое решение.
 */
class ContinueDespiteFinding
{
    use DecidesSafetyFindings;

    /**
     * @return Collection<int, SafetyFinding> находки, к которым относится решение; пусто, если уже решено
     */
    public function handle(User $doctor, SafetyFinding $finding, string $justification): Collection
    {
        if ($doctor->role !== UserRole::Doctor) {
            throw new InvalidArgumentException('По находкам решает только врач.');
        }

        return DB::transaction(function () use ($doctor, $finding, $justification): Collection {
            User::query()->whereKey($finding->patient_id)->lockForUpdate()->firstOrFail();
            $finding = SafetyFinding::query()->lockForUpdate()->findOrFail($finding->id);

            if (! $finding->awaitsDecision()) {
                return new Collection;
            }

            $findings = $this->linked($finding)->prepend($finding);
            $decision = new SafetyDecision($doctor, SafetyDecisionKind::Continued, trim($justification), []);

            foreach ($findings as $decided) {
                $this->decide($decided, $decision);
            }

            PatientSignalsChanged::dispatch($doctor);

            return $findings;
        });
    }

    /**
     * Красные находки без решения на препаратах, с которыми сочетание, если они про это же сочетание.
     *
     * @return Collection<int, SafetyFinding>
     */
    public function linked(SafetyFinding $finding): Collection
    {
        if ($finding->related_prescription_ids === []) {
            return new Collection;
        }

        return SafetyFinding::query()
            ->awaitingDecision()
            ->where('patient_id', $finding->patient_id)
            ->whereIn('prescription_id', $finding->related_prescription_ids)
            ->whereJsonContains('related_prescription_ids', $finding->prescription_id)
            ->orderBy('id')
            ->get();
    }
}
